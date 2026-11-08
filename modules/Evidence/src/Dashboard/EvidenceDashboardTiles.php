<?php

namespace Modules\Evidence\Dashboard;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Evidence\Models\EvidenceAsset;
use Modules\Platform\Dashboard\DashboardTiles;

/**
 * Evidence awaiting a scan or rejected (P1-46), for evidence.view holders, through EvidenceAsset::seenBy like the
 * evidence page: others see only their own uploads in those states.
 */
class EvidenceDashboardTiles implements DashboardTiles
{
    public function __construct(private readonly Gate $gate) {}

    public function order(): int
    {
        return 30;
    }

    public function tilesFor(Authenticatable $viewer): array
    {
        if (! $this->gate->forUser($viewer)->allows('evidence.view')) {
            return [];
        }
        /** @var array<string, int> $byStatus */
        $byStatus = EvidenceAsset::seenBy(EvidenceAsset::query(), $viewer, $this->gate)->whereIn('status', ['quarantined', 'rejected'])
            ->toBase()->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status')->map(fn ($n): int => (int) $n)->all();

        return [[
            'key' => 'evidence_attention', 'title' => 'Evidence needing attention',
            'definition' => 'Evidence you can see that is still waiting for its virus scan, or was rejected by it.', 'href' => route('evidence.index'),
            'counts' => [
                ['label' => 'awaiting scan', 'value' => $byStatus['quarantined'] ?? 0],
                ['label' => 'rejected', 'value' => $byStatus['rejected'] ?? 0],
            ],
        ]];
    }
}
