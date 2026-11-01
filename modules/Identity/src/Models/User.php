<?php

namespace Modules\Identity\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
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
 */
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasRoles, Notifiable;

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
            'clearance' => Classification::class,
        ];
    }
}
