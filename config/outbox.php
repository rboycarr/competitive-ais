<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Transactional outbox (P1-06, docs/01 F3)
    |--------------------------------------------------------------------------
    |
    | batch: events claimed per relay run.
    | lease_seconds: how long a claimed event is reserved for the run that
    |   claimed it; after that another run may take it over.
    | max_attempts: after this many failed deliveries the event is dead-lettered
    |   (failed_at) and reported; it is not retried automatically.
    | backoff_seconds / backoff_max_seconds: exponential retry delay with jitter.
    |
    */

    'batch' => 100,

    'lease_seconds' => 300,

    'max_attempts' => 10,

    'backoff_seconds' => 10,

    'backoff_max_seconds' => 3600,

];
