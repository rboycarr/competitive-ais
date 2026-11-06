<?php

namespace Modules\Intelligence\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;
use Modules\Evidence\Contracts\EvidenceLinking;
use Modules\Intelligence\Models\ClaimEvidenceLink;
use Modules\Intelligence\Models\IntelligenceClaim;
use Modules\Intelligence\Models\IntelligenceItem;
use Modules\Platform\Access\AccessScope;
use Modules\Platform\Audit\Actor;
use Modules\Platform\Audit\AuditLogger;

/**
 * The intelligence workflow (docs/03 Intelligence, state machine), one method per transition. P1-42: submit.
 * - submit: draft or changes_requested to submitted; intelligence.submit; the author or owner, who can see the item;
 *   from the version read; at least one claim, and every claim not withdrawn has at least one `supports` link to an
 *   `available` asset (whoever can see it). Checked under the item's row lock, the cited assets share-locked after it
 *   (so none is withdrawn or rejected meanwhile). Content does not change, so the version stays; it is frozen from
 *   here until changes are requested. Audited with the version and claim count.
 */
class IntelligenceWorkflowService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditLogger $audit,
        private readonly AccessScope $access,
        private readonly Gate $gate,
        private readonly EvidenceLinking $evidence,
    ) {}

    /** @param  array<string, mixed>  $data  version */
    public function submit(Authenticatable $actor, IntelligenceItem $item, array $data): void
    {
        if (! $this->gate->forUser($actor)->allows('intelligence.submit')) {
            throw new AuthorizationException('You are not allowed to submit intelligence for review.');
        }
        $item->ensureSeenBy($actor, $this->gate, $this->access);

        $this->db->transaction(function () use ($actor, $item, $data): void {
            $locked = IntelligenceItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $locked->ensureSeenBy($actor, $this->gate, $this->access);
            IntelligenceItemService::assertMayEdit($actor, $locked, $data['version'] ?? null);
            $problems = $this->problems($locked, lock: true);
            if ($problems !== []) {
                throw ValidationException::withMessages(['claims' => $problems]);
            }
            $claims = $locked->claims()->where('status', '!=', 'withdrawn')->count();
            $locked->forceFill(['status' => 'submitted'])->save();
            $this->audit->record('intelligence.submitted', $locked, ['version' => $locked->version, 'claims' => $claims], Actor::user((int) $actor->getAuthIdentifier()));
            $item->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * What keeps the item from being submitted, in words (empty: nothing). With $lock, the cited assets are
     * share-locked: call it inside the submitting transaction, holding the item's lock.
     *
     * @return list<string>
     */
    public function problems(IntelligenceItem $item, bool $lock = false): array
    {
        $claims = $item->claims()->where('status', '!=', 'withdrawn')->orderBy('position')->orderBy('id')
            ->with(['evidenceLinks' => fn ($q) => $q->where('relation', 'supports')])->get();
        if ($claims->isEmpty()) {
            return ['Add at least one claim.'];
        }
        $cited = array_values(array_unique($claims->flatMap(fn (IntelligenceClaim $c) => $c->evidenceLinks->pluck('evidence_asset_id'))->map(fn ($id): int => (int) $id)->all()));
        sort($cited);
        $available = $this->evidence->available($cited, $lock);

        $problems = [];
        foreach ($claims->values() as $index => $claim) {
            $supported = $claim->evidenceLinks->contains(fn (ClaimEvidenceLink $l): bool => in_array($l->evidence_asset_id, $available, true));
            if (! $supported) {
                $problems[] = 'Claim '.($index + 1).' cites no available evidence that supports it.';
            }
        }

        return $problems;
    }
}
