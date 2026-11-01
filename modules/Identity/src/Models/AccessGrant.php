<?php

namespace Modules\Identity\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Platform\Classification;

/**
 * What a user or team may see beyond their roles (P1-14, docs/02 §3.2, docs/07 §3), up to a classification and until
 * an optional expiry. Revoked, never deleted. Scope `all` or one competitor (P1-20a); markets follow P1-23. No mass
 * assignment.
 *
 * @property int $id
 * @property string $ulid
 * @property ?int $user_id
 * @property ?int $team_id
 * @property string $scope_type
 * @property ?int $competitor_id
 * @property Classification $max_classification
 * @property bool $can_export
 * @property int $granted_by
 * @property string $reason
 * @property ?CarbonImmutable $expires_at
 * @property ?CarbonImmutable $revoked_at
 * @property ?int $revoked_by
 * @property ?CarbonInterface $created_at
 */
class AccessGrant extends Model
{
    public const SCOPE_ALL = 'all';

    public const SCOPE_COMPETITOR = 'competitor';

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * Neither revoked nor expired.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now('UTC')->format('Y-m-d H:i:s.u')));
    }

    /**
     * Grants to the user and to every team they belong to; AccessScope (P1-15) combines these with clearance.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeHeldBy(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('user_id', $user->id)
            ->orWhereIn('team_id', fn ($teams) => $teams->select('team_id')->from('team_user')->where('user_id', $user->id)));
    }

    public function status(): string
    {
        return match (true) {
            $this->revoked_at !== null => 'revoked',
            $this->expires_at !== null && ! $this->expires_at->isFuture() => 'expired',
            default => 'active',
        };
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_classification' => Classification::class,
            'can_export' => 'boolean',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
