<?php

namespace Tests\Support;

use Illuminate\Support\Str;
use Modules\Identity\Models\User;
use Modules\Platform\Classification;
use PragmaRX\Google2FA\Google2FA;

/** Test accounts created directly (invitations have their own tests). */
trait CreatesUsers
{
    protected const TEST_PASSWORD = 'correct horse battery staple';

    protected function createUser(string $email, string $role, string $status = 'active', Classification $clearance = Classification::Internal): User
    {
        $user = new User;
        $user->forceFill([
            'ulid' => (string) Str::ulid(), 'name' => 'Test '.$email, 'email' => $email, 'password' => self::TEST_PASSWORD,
            'clearance' => $clearance, 'status' => $status, 'email_verified_at' => now(),
        ])->save();
        $user->assignRole($role);

        return $user;
    }

    protected function enableTwoFactor(User $user): User
    {
        $user->forceFill([
            'two_factor_secret' => encrypt((new Google2FA)->generateSecretKey()),
            'two_factor_recovery_codes' => encrypt((string) json_encode(array_map(fn () => Str::random(10).'-'.Str::random(10), range(1, 8)))),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user->refresh();
    }
}
