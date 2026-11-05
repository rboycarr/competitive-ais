<?php

namespace Modules\Sources\Lookup;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Modules\Sources\Contracts\SourceLookup;
use Modules\Sources\Contracts\SourcePolicySummary;
use Modules\Sources\Models\Source;
use Modules\Sources\Models\SourcePolicy;

/**
 * SourceLookup over the sources and source_policies tables (P1-37): source.view and AccessScope, as on the source
 * pages. The policy in force is `current_policy_id` while the source is `approved` (P1-31b: a suspended source keeps
 * its last approval on record, not in force).
 */
class EloquentSourceLookup implements SourceLookup
{
    public function __construct(private readonly Gate $gate) {}

    public function usableBy(Authenticatable $viewer): array
    {
        $sources = $this->visible($viewer)->where('status', 'approved')->whereNotNull('current_policy_id')
            ->orderBy('name')->orderBy('id')->get();
        $policies = SourcePolicy::query()->whereIn('id', $sources->pluck('current_policy_id')->all())->get()->keyBy('id');

        return array_values($sources->map(fn (Source $s): SourcePolicySummary => $this->summary($s, $policies->get((int) $s->current_policy_id)))->all());
    }

    public function forCapture(Authenticatable $viewer, string $ulid, bool $lock = false): ?SourcePolicySummary
    {
        $query = $this->visible($viewer)->where('ulid', strtoupper($ulid));
        $source = ($lock ? $query->sharedLock() : $query)->first();
        if ($source === null) {
            return null;
        }
        $policy = $source->status === 'approved' && $source->current_policy_id !== null
            ? SourcePolicy::query()->where('source_id', $source->id)->where('decision', 'approved')->find($source->current_policy_id)
            : null;

        return $this->summary($source, $policy);
    }

    public function recorded(Authenticatable $viewer, int $sourceId, int $policyId): ?SourcePolicySummary
    {
        $source = $this->visible($viewer)->find($sourceId);
        $policy = $source === null ? null
            : SourcePolicy::query()->where('source_id', $source->id)->where('decision', 'approved')->find($policyId);

        return $source === null || $policy === null ? null : $this->summary($source, $policy);
    }

    /** @return Builder<Source> */
    private function visible(Authenticatable $viewer): Builder
    {
        $query = Source::query()->visibleTo($viewer);

        return $this->gate->forUser($viewer)->allows('source.view') ? $query : $query->whereRaw('1 = 0');
    }

    private function summary(Source $source, ?SourcePolicy $policy): SourcePolicySummary
    {
        return new SourcePolicySummary(
            $source->id, $source->ulid, $source->name, $source->status,
            $policy?->id, $policy?->version, $policy === null ? null : CarbonImmutable::instance($policy->decided_at)->utc(),
        );
    }
}
