<?php

namespace Modules\Platform\Dashboard;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A module's dashboard tiles (P1-46, docs/03 Dashboard). Each module answers for its own records, through its own
 * visibility rules; a tile the viewer may not see is left out, and every count covers only what the viewer can see.
 * Bound with the tag below in the module's service provider.
 *
 * A tile: key, title, definition (what it counts), href (the page behind it), and counts (label and value) and/or
 * items (label, href and an ISO-8601 UTC time).
 */
interface DashboardTiles
{
    public const TAG = 'dashboard.tiles';

    /** Where this module's tiles appear: lower first. */
    public function order(): int;

    /**
     * @return list<array{key: string, title: string, definition: string, href: string, counts?: list<array{label: string, value: int}>, items?: list<array{label: string, href: string, at: ?string}>}>
     */
    public function tilesFor(Authenticatable $viewer): array;
}
