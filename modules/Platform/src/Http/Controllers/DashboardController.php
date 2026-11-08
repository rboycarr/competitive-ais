<?php

namespace Modules\Platform\Http\Controllers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Platform\Dashboard\DashboardTiles;

/**
 * The start page (P1-46): for a signed-in user, the tiles every module offers them (DashboardTiles); for a guest, the
 * sign-in link only.
 */
class DashboardController
{
    public function __invoke(Request $request, Container $app): Response
    {
        $tiles = [];
        if (($viewer = $request->user()) !== null) {
            /** @var list<DashboardTiles> $providers */
            $providers = iterator_to_array($app->tagged(DashboardTiles::TAG), false);
            usort($providers, fn (DashboardTiles $a, DashboardTiles $b): int => $a->order() <=> $b->order());
            foreach ($providers as $provider) {
                array_push($tiles, ...$provider->tilesFor($viewer));
            }
        }

        return Inertia::render('Platform/Home', ['tiles' => $tiles]);
    }
}
