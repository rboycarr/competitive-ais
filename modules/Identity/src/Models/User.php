<?php

namespace Modules\Identity\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Modules\Platform\Classification;
use Spatie\Permission\Traits\HasRoles;

/**
 * An account (docs/02 §3.2). Created only by accepting an invitation (P1-10); there is no self-registration.
 * No mass assignment: attributes are set explicitly (docs/07 Input).
 *
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property string $email
 * @property string $status
 * @property Classification $clearance
 * @property ?string $two_factor_secret
 * @property ?string $two_factor_recovery_codes
 * @property ?Carbon $two_factor_confirmed_at
 * @property ?CarbonImmutable $last_login_at
 */
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    use HasRoles, Notifiable, TwoFactorAuthenticatable;

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'immutable_datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'clearance' => Classification::class,
        ];
    }
}
