<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Password hashing (docs/07 Authentication: Argon2id)
    |--------------------------------------------------------------------------
    |
    | Parameters follow the OWASP minimum for Argon2id (memory 19 MiB, 2 passes,
    | 1 lane) with more memory. Existing hashes are upgraded on login. Tests
    | lower the cost through ARGON_MEMORY and ARGON_TIME (phpunit.xml only).
    |
    */

    'driver' => 'argon2id',

    'bcrypt' => [
        'rounds' => 12,
        'verify' => true,
        'limit' => null,
    ],

    'argon' => [
        'memory' => (int) env('ARGON_MEMORY', 65536),
        'threads' => 1,
        'time' => (int) env('ARGON_TIME', 3),
        'verify' => true,
    ],

    'rehash_on_login' => true,

];
