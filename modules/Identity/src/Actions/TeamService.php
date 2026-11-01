<?php

namespace Modules\Identity\Actions;

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
 * Teams and their members (P1-13). The actor is active and holds team.manage; every change is audited. Teams are
 * not deleted here: access grants reference them (P1-14), and history keeps them.
 */
class TeamService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditLogger $audit,
        private readonly AccessScope $access,
    ) {}

    public function create(User $actor, string $name, ?string $description, ?User $lead): Team
    {
        $this->assertMayManage($actor);
        $name = $this->validName($name);
        if (Team::query()->where('name', $name)->exists()) {
            throw ValidationException::withMessages(['name' => 'A team with this name already exists.']);
        }

        return $this->db->transaction(function () use ($actor, $name, $description, $lead): Team {
            $team = new Team;
            $team->forceFill(['ulid' => (string) Str::ulid(), 'name' => $name, 'description' => $this->validDescription($description), 'lead_user_id' => $this->activeLead($lead)])->save();
            $this->audit->record('team.created', $team, array_filter(['lead' => $lead?->ulid]), Actor::user($actor->id), Classification::Internal);

            return $team;
        });
    }

    public function update(User $actor, Team $team, string $name, ?string $description, ?User $lead): void
    {
        $this->assertMayManage($actor);
        $name = $this->validName($name);
        if (Team::query()->where('name', $name)->whereKeyNot($team->id)->exists()) {
            throw ValidationException::withMessages(['name' => 'A team with this name already exists.']);
        }

        $this->db->transaction(function () use ($actor, $team, $name, $description, $lead): void {
            $team->forceFill(['name' => $name, 'description' => $this->validDescription($description), 'lead_user_id' => $this->activeLead($lead)]);
            $changed = array_keys($team->getDirty());
            if ($changed === []) {
                return;
            }
            $team->save();
            $this->audit->record('team.updated', $team, array_filter(['fields' => $changed, 'lead' => $lead?->ulid]), Actor::user($actor->id), Classification::Internal);
        });
    }

    public function addMember(User $actor, Team $team, User $member): void
    {
        $this->assertMayManage($actor);
        if ($member->status !== 'active') {
            throw ValidationException::withMessages(['member' => 'Only active users can join a team.']);
        }

        $this->db->transaction(function () use ($actor, $team, $member): void {
            // Same team row lock as AccessGrantService::grant: joining a team that holds grants would be a self-grant
            // (P1-14, docs/07 T2); others may add the actor.
            Team::query()->whereKey($team->id)->lockForUpdate()->first();
            if ($member->is($actor) && AccessGrant::query()->active()->where('team_id', $team->id)->exists()) {
                throw ValidationException::withMessages(['member' => 'You cannot add yourself to a team that holds access grants.']);
            }
            if ($team->members()->whereKey($member->id)->exists()) {
                return;
            }
            $team->members()->attach($member->id, ['added_by' => $actor->id, 'created_at' => now('UTC')->format('Y-m-d H:i:s.u')]);
            $this->audit->record('team.member_added', $team, ['member' => $member->ulid], Actor::user($actor->id), Classification::Internal);
        });
        $this->access->forget(); // team grants reach members (P1-15)
    }

    public function removeMember(User $actor, Team $team, User $member): void
    {
        $this->assertMayManage($actor);

        $this->db->transaction(function () use ($actor, $team, $member): void {
            if ($team->members()->detach($member->id) === 0) {
                return;
            }
            $this->audit->record('team.member_removed', $team, ['member' => $member->ulid], Actor::user($actor->id), Classification::Internal);
        });
        $this->access->forget();
    }

    private function assertMayManage(User $actor): void
    {
        if ($actor->status !== 'active' || ! $actor->hasPermissionTo('team.manage')) {
            throw new AuthorizationException('You are not allowed to manage teams.');
        }
    }

    private function validName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw ValidationException::withMessages(['name' => 'A team name is required (at most 100 characters).']);
        }

        return $name;
    }

    private function validDescription(?string $description): ?string
    {
        $description = $description === null ? null : trim($description);
        if ($description !== null && mb_strlen($description) > 500) {
            throw ValidationException::withMessages(['description' => 'At most 500 characters.']);
        }

        return $description === '' ? null : $description;
    }

    private function activeLead(?User $lead): ?int
    {
        if ($lead !== null && $lead->status !== 'active') {
            throw ValidationException::withMessages(['lead' => 'The team lead must be an active user.']);
        }

        return $lead?->id;
    }
}
