<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Password hashing (docs/07 Authentication: Argon2id)
    |--------------------------------------------------------------------------
    |
    | Parameters follow the OWASP minimum for Argon2id (memory 19 MiB, 2 passes,
    | 1 lane) with more memory. Existing hashes are upgraded on login. Fixed
    | values, not environment variables (TASK-0017 #4); only tests/TestCase.php
    | lowers them, in code.
    |
    */

    'driver' => 'argon2id',

    'bcrypt' => [
        'rounds' => 12,
        'verify' => true,
        'limit' => null,
    ],

    'argon' => [
        'memory' => 65536,
        'threads' => 1,
        'time' => 3,
        'verify' => true,
    ],

    'rehash_on_login' => true,

];
