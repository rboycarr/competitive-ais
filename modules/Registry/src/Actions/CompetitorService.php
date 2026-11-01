<?php

namespace Modules\Registry\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Platform\Access\AccessScope;
use Modules\Platform\Audit\Actor;
use Modules\Platform\Audit\AuditLogger;
use Modules\Platform\Classification;
use Modules\Registry\Models\Competitor;

/**
 * Competitor registry rules (P1-20, docs/03 Registry):
 * - creating, editing and lifecycle changes need competitor.manage, and the entry must be visible to the actor;
 * - nobody classifies an entry above what they can see (AccessScope decides), and lowering a classification needs
 *   intelligence.publish (docs/07 §3: downgrades are a Director decision);
 * - the assigned analyst is an active holder of competitor.manage when assigned, checked under a lock on the user row
 *   (TASK-0031 #2); a later suspension leaves the assignment until someone changes it;
 * - every change is audited in the same transaction. Domain events (docs/03: CompetitorCreated, …) are published
 *   once they have a consumer, the Phase 2 search indexer: the outbox refuses events nobody handles.
 * Archiving is allowed for now: the guard against open intelligence items comes with those items (binding, docs/02).
 */
class CompetitorService
{
    /** Fields an update may change; the slug stays fixed (pages link by ULID). */
    private const FIELDS = ['name', 'legal_name', 'website', 'hq_country', 'tier', 'assigned_analyst_id', 'classification', 'description'];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditLogger $audit,
        private readonly AccessScope $access,
        private readonly UserDirectory $users,
        private readonly Gate $gate,
    ) {}

    /**
     * @param  array{name: string, legal_name?: ?string, website?: ?string, hq_country?: ?string, tier?: ?string, analyst?: ?string, classification: string, description?: ?string}  $data
     */
    public function create(Authenticatable $actor, array $data): Competitor
    {
        $this->assertMayManage($actor);
        $competitor = new Competitor;
        $competitor->forceFill($this->attributes($data) + [
            'ulid' => (string) Str::ulid(),
            'lifecycle' => 'active',
            'created_by' => $actor->getAuthIdentifier(),
        ]);
        $this->assertVisibleAfterChange($actor, $competitor);

        for ($attempt = 1; ; $attempt++) {
            $competitor->slug = $this->slug($competitor->name, $attempt);
            try {
                return $this->db->transaction(function () use ($actor, $competitor, $data): Competitor {
                    $competitor->assigned_analyst_id = $this->analystId($data['analyst'] ?? null);
                    $competitor->save();
                    $this->audit->record('competitor.created', $competitor, ['lifecycle' => 'active', 'classification' => $competitor->classification->value], $this->actor($actor));

                    return $competitor;
                });
            } catch (UniqueConstraintViolationException $e) {
                // Two entries with the same name at once: try the next slug suffix.
                if ($attempt >= 20) {
                    throw $e;
                }
                $competitor->exists = false;
            }
        }
    }

    /**
     * @param  array{name: string, legal_name?: ?string, website?: ?string, hq_country?: ?string, tier?: ?string, analyst?: ?string, classification: string, description?: ?string}  $data
     */
    public function update(Authenticatable $actor, Competitor $competitor, array $data): void
    {
        $this->assertMayManage($actor);
        $this->access->ensureVisible($actor, $competitor);

        $this->db->transaction(function () use ($actor, $competitor, $data): void {
            $locked = Competitor::query()->whereKey($competitor->id)->lockForUpdate()->firstOrFail();
            $before = $locked->classification;
            $current = $locked->assigned_analyst_id;
            $locked->forceFill($this->attributes($data));
            $locked->assigned_analyst_id = $this->analystId($data['analyst'] ?? null, $current);
            $changed = array_values(array_intersect(self::FIELDS, array_keys($locked->getDirty())));
            if ($changed === []) {
                return;
            }
            if ($before->rank() > $locked->classification->rank() && ! $this->gate->forUser($actor)->allows('intelligence.publish')) {
                throw ValidationException::withMessages(['classification' => 'Only a CI Director can lower a classification.']);
            }
            $this->assertVisibleAfterChange($actor, $locked);

            $locked->save();
            $this->audit->record('competitor.updated', $locked, ['fields' => $changed], $this->actor($actor));
            $competitor->setRawAttributes($locked->getAttributes(), true);
        });
    }

    public function setLifecycle(Authenticatable $actor, Competitor $competitor, string $lifecycle): void
    {
        $this->assertMayManage($actor);
        $this->access->ensureVisible($actor, $competitor);
        if (! in_array($lifecycle, Competitor::LIFECYCLES, true)) {
            throw ValidationException::withMessages(['lifecycle' => 'Unknown lifecycle.']);
        }

        $this->db->transaction(function () use ($actor, $competitor, $lifecycle): void {
            $locked = Competitor::query()->whereKey($competitor->id)->lockForUpdate()->firstOrFail();
            $from = $locked->lifecycle;
            if ($from === $lifecycle) {
                return;
            }

            $locked->forceFill(['lifecycle' => $lifecycle])->save();
            $this->audit->record('competitor.lifecycle_changed', $locked, ['from' => $from, 'to' => $lifecycle], $this->actor($actor));
            $competitor->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * @param  array{name: string, legal_name?: ?string, website?: ?string, hq_country?: ?string, tier?: ?string, analyst?: ?string, classification: string, description?: ?string}  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $text = fn (?string $value): ?string => ($value = trim((string) $value)) === '' ? null : $value;
        $name = $text($data['name']) ?? throw ValidationException::withMessages(['name' => 'A name is required.']);
        $website = $text($data['website'] ?? null);
        if ($website !== null && ! self::isWebAddress($website)) {
            throw ValidationException::withMessages(['website' => 'The website must be an http:// or https:// address.']);
        }
        $tier = $text($data['tier'] ?? null);
        if ($tier !== null && ! in_array($tier, Competitor::TIERS, true)) {
            throw ValidationException::withMessages(['tier' => 'Unknown tier.']);
        }

        return [
            'name' => preg_replace('/\s+/u', ' ', $name),
            'legal_name' => $text($data['legal_name'] ?? null),
            'website' => $website,
            'hq_country' => ($country = $text($data['hq_country'] ?? null)) === null ? null : strtoupper($country),
            'tier' => $tier,
            'classification' => Classification::tryFrom($data['classification']) ?? throw ValidationException::withMessages(['classification' => 'Unknown classification.']),
            'description' => $text($data['description'] ?? null),
        ];
    }

    /** One rule for every caller (TASK-0031 #3): a valid URL with an http or https scheme and a host. */
    public static function isWebAddress(string $value): bool
    {
        $parts = filter_var($value, FILTER_VALIDATE_URL) === false ? false : parse_url($value);

        return is_array($parts) && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) && ($parts['host'] ?? '') !== '';
    }

    /** Inside the write transaction. Keeping the current analyst needs no check; a new one must qualify now. */
    private function analystId(?string $ulid, ?int $current = null): ?int
    {
        $ulid = trim((string) $ulid);
        if ($ulid === '') {
            return null;
        }
        if ($current !== null && ($this->users->summaries([$current])[$current]->ulid ?? null) === strtoupper($ulid)) {
            return $current;
        }

        return $this->users->lockedActiveHolder($ulid, 'competitor.manage')->id
            ?? throw ValidationException::withMessages(['analyst' => 'The analyst must be an active user who manages competitors.']);
    }

    private function assertMayManage(Authenticatable $actor): void
    {
        if (! $this->gate->forUser($actor)->allows('competitor.manage')) {
            throw new AuthorizationException('You are not allowed to manage competitors.');
        }
    }

    /** An actor may not create or leave an entry they could not see themselves. */
    private function assertVisibleAfterChange(Authenticatable $actor, Competitor $competitor): void
    {
        if (! $this->access->allows($actor, $competitor)) {
            throw ValidationException::withMessages(['classification' => 'You cannot classify an entry above what you can see.']);
        }
    }

    private function slug(string $name, int $attempt): string
    {
        $base = Str::slug(Str::ascii($name)) ?: 'competitor';
        $base = rtrim(substr($base, 0, 200), '-');
        if ($attempt === 1 && ! Competitor::query()->where('slug', $base)->exists()) {
            return $base;
        }
        // Suffixes start at 2 ("acme", "acme-2", …); a retry after a concurrent insert starts one higher.
        $suffix = $attempt + 1;
        while (Competitor::query()->where('slug', "$base-$suffix")->exists()) {
            $suffix++;
        }

        return "$base-$suffix";
    }

    private function actor(Authenticatable $actor): Actor
    {
        return Actor::user((int) $actor->getAuthIdentifier());
    }
}
