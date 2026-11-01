<?php

use Laravel\Fortify\Features;

/*
|--------------------------------------------------------------------------
| Fortify (P1-11, docs/07 Authentication)
|--------------------------------------------------------------------------
|
| Login with email and password, plus TOTP two-factor authentication with
| recovery codes. Deliberately off: registration (accounts come only from
| invitations, P1-10), password reset and profile/password updates (later
| stories), email verification (the invitation proves the address), and
| passkeys. Pages are Inertia views registered in IdentityServiceProvider.
|
*/

return [

    'guard' => 'web',

    'passwords' => 'users',

    'username' => 'email',

    'email' => 'email',

    'lowercase_usernames' => true,

    'home' => '/',

    'prefix' => '',

    'domain' => null,

    'middleware' => ['web'],

    // Rate limiters defined in IdentityServiceProvider (per account and IP).
    'limiters' => [
        'login' => 'login',
        'two-factor' => 'two-factor',
    ],

    'views' => true,

    'features' => [
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]),
    ],

];
