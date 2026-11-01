<?php

namespace Modules\Identity\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Identity\Models\AccessGrant;
use Modules\Identity\Models\Team;
use Modules\Identity\Models\User;
use Modules\Platform\Access\AccessScope;
use Modules\Platform\Audit\Actor;
use Modules\Platform\Audit\AuditLogger;
use Modules\Platform\Classification;

/**
 * Access grants (P1-14, docs/07 §3, T2). The actor is active and holds grant.manage (which needs two-factor). Nobody
 * grants to themselves, directly or through a team they belong to (TeamService refuses the reverse order: joining a
 * team that holds grants). A grant opens at most the actor's own clearance, and `can_export` only if the actor holds
 * export.run. Grants are revoked, never deleted; every change is audited.
 */
class AccessGrantService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditLogger $audit,
        private readonly AccessScope $access,
    ) {}

    public function grant(User $actor, User|Team $grantee, Classification $maxClassification, bool $canExport, string $reason, ?CarbonImmutable $expiresAt): AccessGrant
    {
        $this->assertMayManage($actor);
        $reason = $this->validReason($reason);
        if ($expiresAt !== null && ! $expiresAt->isFuture()) {
            throw ValidationException::withMessages(['expires_at' => 'The expiry must be in the future.']);
        }
        if (! $actor->clearance->isAtLeast($maxClassification)) {
            throw ValidationException::withMessages(['max_classification' => 'You cannot grant access above your own clearance.']);
        }
        // checkPermissionTo, not hasPermissionTo: export.run is not seeded before exports exist (Phase 2).
        if ($canExport && ! $actor->checkPermissionTo('export.run')) {
            throw ValidationException::withMessages(['can_export' => 'You cannot grant exports without being allowed to export.']);
        }

        $grant = $this->db->transaction(function () use ($actor, $grantee, $maxClassification, $canExport, $reason, $expiresAt): AccessGrant {
            if ($grantee instanceof User) {
                $grantee = User::query()->whereKey($grantee->id)->lockForUpdate()->firstOrFail();
                if ($grantee->is($actor)) {
                    throw new AuthorizationException('You cannot grant access to yourself.');
                }
                if ($grantee->status !== 'active') {
                    throw ValidationException::withMessages(['grantee' => 'Only active users can receive access.']);
                }
            } else {
                // The team row lock orders this against TeamService::addMember, so the actor cannot join in between.
                $grantee = Team::query()->whereKey($grantee->id)->lockForUpdate()->firstOrFail();
                if ($grantee->members()->whereKey($actor->id)->exists()) {
                    throw new AuthorizationException('You cannot grant access to a team you belong to.');
                }
            }

            $grant = new AccessGrant;
            $grant->forceFill([
                'ulid' => (string) Str::ulid(),
                'user_id' => $grantee instanceof User ? $grantee->id : null,
                'team_id' => $grantee instanceof Team ? $grantee->id : null,
                'scope_type' => AccessGrant::SCOPE_ALL,
                'max_classification' => $maxClassification,
                'can_export' => $canExport,
                'granted_by' => $actor->id,
                'reason' => $reason,
                'expires_at' => $expiresAt?->utc(),
            ])->save();

            $this->audit->record('access_grant.created', $grant, array_filter([
                'grantee_type' => $grantee instanceof User ? 'user' : 'team',
                'grantee' => $grantee->ulid,
                'scope' => AccessGrant::SCOPE_ALL,
                'max_classification' => $maxClassification->value,
                'can_export' => $canExport,
                'expires_at' => $expiresAt?->getTimestamp(),
            ], fn ($value) => $value !== null), Actor::user($actor->id), Classification::Internal);

            return $grant;
        });
        $this->access->forget(); // visibility changes from now on, also later in this request (P1-15)

        return $grant;
    }

    public function revoke(User $actor, AccessGrant $grant): void
    {
        $this->assertMayManage($actor);

        $this->db->transaction(function () use ($actor, $grant): void {
            $locked = AccessGrant::query()->whereKey($grant->id)->lockForUpdate()->firstOrFail();
            if ($locked->revoked_at !== null) {
                throw ValidationException::withMessages(['grant' => 'This grant is already revoked.']);
            }

            $locked->forceFill(['revoked_at' => now('UTC'), 'revoked_by' => $actor->id])->save();
            $grantee = $locked->user_id !== null ? User::query()->findOrFail($locked->user_id) : Team::query()->findOrFail($locked->team_id);
            $this->audit->record('access_grant.revoked', $locked, [
                'grantee_type' => $grantee instanceof User ? 'user' : 'team',
                'grantee' => $grantee->ulid,
            ], Actor::user($actor->id), Classification::Internal);
        });
        $this->access->forget();
    }

    private function assertMayManage(User $actor): void
    {
        if ($actor->status !== 'active' || ! $actor->hasPermissionTo('grant.manage')) {
            throw new AuthorizationException('You are not allowed to manage access grants.');
        }
    }

    private function validReason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'A reason is required (3 to 500 characters).']);
        }

        return $reason;
    }
}
