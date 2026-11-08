<?php

namespace Modules\Sources\Dashboard;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Platform\Dashboard\DashboardTiles;
use Modules\Sources\Models\Source;

/** Sources by status (P1-46), for source.view holders, through Source::visibleTo like the sources page. */
class SourcesDashboardTiles implements DashboardTiles
{
    public function __construct(private readonly Gate $gate) {}

    public function order(): int
    {
        return 20;
    }

    public function tilesFor(Authenticatable $viewer): array
    {
        if (! $this->gate->forUser($viewer)->allows('source.view')) {
            return [];
        }
        /** @var array<string, int> $byStatus */
        $byStatus = Source::query()->visibleTo($viewer)->toBase()->selectRaw('status, COUNT(*) AS n')->groupBy('status')
            ->pluck('n', 'status')->map(fn ($n): int => (int) $n)->all();

        return [[
            'key' => 'sources_by_status', 'title' => 'Sources by status',
            'definition' => 'Sources you can see, counted by lifecycle status.', 'href' => route('sources.index'),
            'counts' => array_map(fn (string $s): array => ['label' => str_replace('_', ' ', $s), 'value' => $byStatus[$s] ?? 0], Source::STATUSES),
        ]];
    }
}
