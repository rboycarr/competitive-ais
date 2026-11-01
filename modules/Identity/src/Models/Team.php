<?php

namespace Modules\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A team of users (P1-13, docs/02 §3.2); access grants can target a team (P1-14). No mass assignment.
 *
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property ?string $description
 * @property ?int $lead_user_id
 */
class Team extends Model
{
    protected $guarded = ['*'];

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('added_by', 'created_at');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_user_id');
    }
}
