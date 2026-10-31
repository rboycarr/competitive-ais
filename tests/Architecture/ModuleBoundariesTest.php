<?php

/*
| Module boundaries (ADR-0001, docs/01 §3). A module may use Modules\Platform and other modules'
| Contracts and Events only. Platform is the shared kernel and uses no other module. Modules do not
| reach into App\ (application wiring).
*/

foreach (modules() as $module) {
    foreach (modules() as $other) {
        // Platform has the stricter rule below; everyone may use Platform.
        if ($other === $module || $other === 'Platform' || $module === 'Platform') {
            continue;
        }

        arch("$module uses only the Contracts and Events of $other")
            ->expect("Modules\\$module")
            ->not->toUse("Modules\\$other")
            ->ignoring(["Modules\\$other\\Contracts", "Modules\\$other\\Events"]);
    }

    arch("$module does not use App")
        ->expect("Modules\\$module")
        ->not->toUse('App');
}

foreach (modules() as $other) {
    if ($other !== 'Platform') {
        arch("Platform does not depend on $other")
            ->expect('Modules\Platform')
            ->not->toUse("Modules\\$other");
    }
}
