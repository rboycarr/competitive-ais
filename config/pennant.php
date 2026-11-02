<?php

/*
|--------------------------------------------------------------------------
| Feature flags (Laravel Pennant)
|--------------------------------------------------------------------------
|
| Flags are stored in the `features` table, the single source of truth, and are read and switched only through
| Modules\Platform\Support\Features (docs/01, docs/10): every flag is off until an operator runs the audited
| `php artisan feature:switch <flag> on`. The store is fixed to the database, not read from the environment, so a
| setting cannot swap in the in-memory store and silently lose a switch.
|
*/

return [

    'default' => 'database',

    'stores' => [

        'database' => [
            'driver' => 'database',
            'connection' => null,
            'table' => 'features',
        ],

    ],
];
