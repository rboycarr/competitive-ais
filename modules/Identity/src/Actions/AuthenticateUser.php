<?php

namespace Modules\Identity\Actions;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Str;
use Modules\Identity\Models\User;

/**
 * Password check for sign-in (P1-11). Only active accounts pass. Every attempt runs exactly one Argon2id
 * verification at the production cost, also for unknown and inactive accounts, so neither the response nor its
 * timing tells an attacker whether an address has an account or whether it is suspended (TASK-0019 #1).
 */
class AuthenticateUser
{
    /**
     * Argon2id hash of a random password that was discarded, with the production parameters from config/hashing.php
     * (memory 65536, time 3, threads 1). It never matches; it only makes failed lookups cost the same as real ones.
     * LoginTest checks that its parameters still equal the configured ones.
     */
    public const DUMMY_HASH = '$argon2id$v=19$m=65536,t=3,p=1$RzNiSGwvZk9zQjU0bGNydg$2x5nDupSZikymKXN0Pq3IifZt4c7pzbqFXzPg5/x/YU';

    public function __construct(private readonly Hasher $hasher) {}

    public function __invoke(string $email, string $password): ?User
    {
        $user = User::query()->where('email', Str::lower(trim($email)))->first();
        $matches = $this->hasher->check($password, $user !== null ? $user->password : self::DUMMY_HASH);

        return $user !== null && $matches && $user->status === 'active' ? $user : null;
    }
}
