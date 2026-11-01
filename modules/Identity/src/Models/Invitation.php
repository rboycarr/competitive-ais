<?php

namespace Modules\Identity\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Platform\Classification;

/**
 * An invitation to create an account (P1-10). Only the SHA-256 of the token is stored.
 *
 * @property int $id
 * @property string $ulid
 * @property string $email
 * @property string $role_name
 * @property Classification $clearance
 * @property string $token_hash
 * @property ?int $invited_by
 * @property CarbonImmutable $expires_at
 * @property ?CarbonImmutable $accepted_at
 * @property ?int $accepted_user_id
 * @property ?CarbonImmutable $revoked_at
 */
class Invitation extends Model
{
    protected $table = 'user_invitations';

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'clearance' => Classification::class,
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
