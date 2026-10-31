<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Audit trail (P1-05, ADR-0006)
    |--------------------------------------------------------------------------
    |
    | lag_minutes: a checkpoint closes only rows older than this. Business
    | transactions must stay well below it; a row that commits later inside a
    | closed range is reported as late_commit.
    |
    | generation: checkpoint series. A new generation starts with signed
    | manifests (P1-50) or a key rotation.
    |
    */

    'lag_minutes' => 15,

    'generation' => 1,

];
