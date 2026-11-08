<?php

namespace Modules\Intelligence\Dashboard;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Modules\Intelligence\Models\IntelligenceItem;
use Modules\Platform\Dashboard\DashboardTiles;

/**
 * Intelligence tiles (P1-46): items by status and recently published for anyone with intelligence.view; the
 * verification backlog by age and the viewer's own assignments for reviewers and directors. Every query goes through
 * IntelligenceItem::seenBy, the same rule as the item pages.
 */
class IntelligenceDashboardTiles implements DashboardTiles
{
    private const RECENT = 5;

    /** Backlog buckets: label => submitted within this many days (null: older). */
    private const AGES = ['under 1 day' => 1, '1–3 days' => 3, '3–7 days' => 7, 'over 7 days' => null];

    public function __construct(private readonly Gate $gate) {}

    public function order(): int
    {
        return 10;
    }

    public function tilesFor(Authenticatable $viewer): array
    {
        $gate = $this->gate->forUser($viewer);
        if (! $gate->allows('intelligence.view')) {
            return [];
        }
        $seen = fn (): Builder => IntelligenceItem::seenBy(IntelligenceItem::query(), $viewer, $this->gate);

        /** @var array<string, int> $byStatus */
        $byStatus = $seen()->toBase()->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status')->map(fn ($n): int => (int) $n)->all();
        $tiles = [[
            'key' => 'intelligence_by_status', 'title' => 'Intelligence by status',
            'definition' => 'Intelligence items you can see, counted by workflow status.', 'href' => route('intelligence.index'),
            'counts' => array_values(array_map(fn (string $s): array => ['label' => str_replace('_', ' ', $s), 'value' => $byStatus[$s]],
                array_filter(IntelligenceItem::STATUSES, fn (string $s): bool => isset($byStatus[$s])))),
        ], [
            'key' => 'recently_published', 'title' => 'Recently published',
            'definition' => 'The latest items published here that you can see, newest first.', 'href' => route('intelligence.index'),
            'items' => $this->items($seen()->where('status', 'published')->orderByDesc('released_at')->orderByDesc('id'), 'released_at'),
        ]];

        if ($gate->allows('intelligence.review') || $gate->allows('intelligence.publish')) {
            $now = CarbonImmutable::now('UTC');
            $counts = [];
            $newer = null;
            foreach (self::AGES as $label => $days) {
                $query = $seen()->whereIn('status', IntelligenceItem::IN_QUEUE);
                if ($days !== null) {
                    $query->where('submitted_at', '>=', $now->subDays($days));
                }
                if ($newer !== null) {
                    $query->where('submitted_at', '<', $now->subDays($newer));
                }
                $counts[] = ['label' => $label, 'value' => $query->count()];
                $newer = $days;
            }
            $tiles[] = [
                'key' => 'verification_backlog', 'title' => 'Verification backlog',
                'definition' => 'Submitted and in-review items you can see, by time since submission.', 'href' => route('verification.index'),
                'counts' => $counts,
            ];
            $mine = $seen()->whereIn('status', IntelligenceItem::IN_QUEUE)->where('assigned_reviewer_id', $viewer->getAuthIdentifier());
            $tiles[] = [
                'key' => 'my_assignments', 'title' => 'My assignments',
                'definition' => 'Items assigned to you for review and not yet decided, oldest submission first.',
                'href' => route('verification.index', ['assigned' => 'mine']),
                'counts' => [['label' => 'assigned to me', 'value' => (clone $mine)->count()]],
                'items' => $this->items($mine->orderBy('submitted_at')->orderBy('id'), 'submitted_at'),
            ];
        }

        return $tiles;
    }

    /**
     * @param  Builder<IntelligenceItem>  $query
     * @return list<array{label: string, href: string, at: ?string}>
     */
    private function items(Builder $query, string $time): array
    {
        return array_values($query->limit(self::RECENT)->get(['id', 'ulid', 'title', $time])->map(fn (IntelligenceItem $item): array => [
            'label' => $item->title,
            'href' => route('intelligence.show', $item),
            'at' => $item->{$time}?->toIso8601ZuluString(),
        ])->all());
    }
}
