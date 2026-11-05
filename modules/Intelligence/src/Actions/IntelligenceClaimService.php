<?php

namespace Modules\Intelligence\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Intelligence\Models\IntelligenceClaim;
use Modules\Intelligence\Models\IntelligenceItem;
use Modules\Platform\Access\AccessScope;
use Modules\Platform\Audit\Actor;
use Modules\Platform\Audit\AuditLogger;

/**
 * The claims of an item (P1-40, docs/02 §3.6, ADR-0010):
 * - a claim is a statement (plain text, at most MAX_STATEMENT characters) with its type, kept apart from its two
 *   human-assigned Admiralty ratings: source reliability A–F and information credibility 1–6. Both are chosen by the
 *   writer, never defaulted (a source's default reliability pre-fills them once claims cite evidence, P1-41);
 * - claims change under the item's rules (IntelligenceItemService::assertMayEdit): editable item, author or owner,
 *   from the version read, under the item's row lock; every change raises the item's version;
 * - a claim is withdrawn, never deleted, so what a reviewer saw stays on record; withdrawn claims do not move;
 * - at most MAX_CLAIMS active claims per item; new claims go last; moving swaps a claim with its active neighbour;
 * - audited in the same transaction with codes only (never the statement), on the item.
 */
class IntelligenceClaimService
{
    public const MAX_STATEMENT = 2000;

    public const MAX_CLAIMS = 50;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditLogger $audit,
        private readonly AccessScope $access,
        private readonly Gate $gate,
    ) {}

    /** @param  array<string, mixed>  $data  statement, claim_type, source_reliability, info_credibility, version */
    public function add(Authenticatable $actor, IntelligenceItem $item, array $data): IntelligenceClaim
    {
        $attributes = $this->attributes($data);

        return $this->inItem($actor, $item, $data, function (IntelligenceItem $locked) use ($actor, $attributes): IntelligenceClaim {
            $active = $locked->claims()->where('status', '!=', 'withdrawn');
            if ($active->count() >= self::MAX_CLAIMS) {
                throw ValidationException::withMessages(['statement' => 'An item has at most '.self::MAX_CLAIMS.' claims.']);
            }
            $claim = new IntelligenceClaim;
            $claim->forceFill($attributes + [
                'ulid' => (string) Str::ulid(),
                'intelligence_item_id' => $locked->id,
                'position' => (int) $locked->claims()->max('position') + 1,
                'status' => 'active',
                'created_by' => (int) $actor->getAuthIdentifier(),
            ])->save();
            $this->recorded('intelligence.claim_added', $actor, $locked, $claim);

            return $claim;
        });
    }

    /** @param  array<string, mixed>  $data  as for add() */
    public function update(Authenticatable $actor, IntelligenceItem $item, string $claimUlid, array $data): IntelligenceClaim
    {
        $attributes = $this->attributes($data);

        return $this->inItem($actor, $item, $data, function (IntelligenceItem $locked) use ($actor, $claimUlid, $attributes): IntelligenceClaim {
            $claim = $this->activeClaim($locked, $claimUlid);
            $claim->forceFill($attributes);
            $fields = array_values(array_filter(['statement', 'claim_type', 'source_reliability', 'info_credibility'], fn (string $f): bool => $claim->isDirty($f)));
            if ($fields === []) {
                return $claim;
            }
            $claim->save();
            $this->recorded('intelligence.claim_updated', $actor, $locked, $claim, ['fields' => $fields]);

            return $claim;
        }, fn (IntelligenceClaim $claim): bool => $claim->wasChanged());
    }

    /** @param  array<string, mixed>  $data  version */
    public function withdraw(Authenticatable $actor, IntelligenceItem $item, string $claimUlid, array $data): void
    {
        $this->inItem($actor, $item, $data, function (IntelligenceItem $locked) use ($actor, $claimUlid): IntelligenceClaim {
            $claim = $this->activeClaim($locked, $claimUlid);
            $claim->forceFill(['status' => 'withdrawn'])->save();
            $this->recorded('intelligence.claim_withdrawn', $actor, $locked, $claim);

            return $claim;
        });
    }

    /** @param  array<string, mixed>  $data  direction (up, down), version */
    public function move(Authenticatable $actor, IntelligenceItem $item, string $claimUlid, array $data): void
    {
        $direction = $data['direction'] ?? null;
        if (! in_array($direction, ['up', 'down'], true)) {
            throw ValidationException::withMessages(['direction' => 'Move a claim up or down.']);
        }
        $this->inItem($actor, $item, $data, function (IntelligenceItem $locked) use ($actor, $claimUlid, $direction): ?IntelligenceClaim {
            $claim = $this->activeClaim($locked, $claimUlid);
            $neighbour = $locked->claims()->where('status', '!=', 'withdrawn')
                ->where('position', $direction === 'up' ? '<' : '>', $claim->position)
                ->orderBy('position', $direction === 'up' ? 'desc' : 'asc')->first();
            if ($neighbour === null) {
                return null; // already first or last
            }
            [$claim->position, $neighbour->position] = [$neighbour->position, $claim->position];
            $claim->save();
            $neighbour->save();
            $this->recorded('intelligence.claim_moved', $actor, $locked, $claim, ['direction' => $direction]);

            return $claim;
        }, fn (?IntelligenceClaim $claim): bool => $claim !== null);
    }

    /**
     * Runs $change under the item's row lock after the edit checks, then raises the item's version when $changed says
     * the change did something (by default: whenever it returned).
     *
     * @template T
     *
     * @param  array<string, mixed>  $data
     * @param  callable(IntelligenceItem): T  $change
     * @param  (callable(T): bool)|null  $changed
     * @return T
     */
    private function inItem(Authenticatable $actor, IntelligenceItem $item, array $data, callable $change, ?callable $changed = null): mixed
    {
        if (! $this->gate->forUser($actor)->allows('intelligence.create')) {
            throw new AuthorizationException('You are not allowed to write intelligence.');
        }
        $item->ensureSeenBy($actor, $this->gate, $this->access);

        return $this->db->transaction(function () use ($actor, $item, $data, $change, $changed): mixed {
            $locked = IntelligenceItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $locked->ensureSeenBy($actor, $this->gate, $this->access);
            IntelligenceItemService::assertMayEdit($actor, $locked, $data['version'] ?? null);
            $result = $change($locked);
            if ($changed === null || $changed($result)) {
                $locked->version++;
                $locked->save();
            }
            $item->setRawAttributes($locked->getAttributes(), true);

            return $result;
        });
    }

    private function activeClaim(IntelligenceItem $locked, string $ulid): IntelligenceClaim
    {
        $claim = $locked->claims()->where('ulid', strtoupper($ulid))->where('status', '!=', 'withdrawn')->first();

        return $claim ?? throw ValidationException::withMessages(['claim' => 'That claim is not on this item, or it was withdrawn.']);
    }

    /** @param  array<string, mixed>  $extra */
    private function recorded(string $action, Authenticatable $actor, IntelligenceItem $locked, IntelligenceClaim $claim, array $extra = []): void
    {
        $this->audit->record($action, $locked, [
            'claim_ulid' => $claim->ulid,
            'claim_type' => $claim->claim_type,
            'rating' => strtolower($claim->rating()),
            'version' => $locked->version + 1, // the version this change creates
        ] + $extra, Actor::user((int) $actor->getAuthIdentifier()));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{statement: string, claim_type: string, source_reliability: string, info_credibility: int}
     */
    private function attributes(array $data): array
    {
        $statement = IntelligenceItemService::text($data, 'statement', self::MAX_STATEMENT, multiline: true)
            ?? throw ValidationException::withMessages(['statement' => 'Write the claim.']);
        $type = $data['claim_type'] ?? null;
        if (! in_array($type, IntelligenceClaim::TYPES, true)) {
            throw ValidationException::withMessages(['claim_type' => 'Choose what kind of claim this is.']);
        }
        $reliability = $data['source_reliability'] ?? null;
        if (! in_array($reliability, IntelligenceClaim::RELIABILITIES, true)) {
            throw ValidationException::withMessages(['source_reliability' => 'Rate the source A to F (F: cannot be judged).']);
        }
        $credibility = $data['info_credibility'] ?? null;
        $credibility = is_int($credibility) || (is_string($credibility) && ctype_digit($credibility)) ? (int) $credibility : null;
        if (! in_array($credibility, IntelligenceClaim::CREDIBILITIES, true)) {
            throw ValidationException::withMessages(['info_credibility' => 'Rate the information 1 to 6 (6: cannot be judged).']);
        }

        return ['statement' => $statement, 'claim_type' => $type, 'source_reliability' => $reliability, 'info_credibility' => $credibility];
    }
}
