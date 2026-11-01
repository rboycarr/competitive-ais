<?php

namespace Modules\Identity\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;
use Modules\Identity\Models\User;
use Modules\Platform\Audit\Actor;
use Modules\Platform\Audit\AuditLogger;
use Modules\Platform\Classification;
use Spatie\Permission\Models\Role;

/**
 * Which roles a user holds (P1-13, docs/03 Identity, docs/07 §3). Rules:
 * - the actor is active and holds user.manage (which needs confirmed two-factor, P1-12);
 * - nobody changes their own roles;
 * - only a super_admin gives or takes super_admin, and the last active super_admin keeps it;
 * - except for a super_admin, nobody gives a role with permissions they do not hold themselves.
 * Every added and removed role is audited (role.assigned, role.revoked) in the same transaction.
 */
class RoleAssignmentService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<string>  $roleNames  the complete new set of roles
     */
    public function setRoles(User $actor, User $target, array $roleNames): void
    {
        if ($actor->status !== 'active' || ! $actor->hasPermissionTo('user.manage')) {
            throw new AuthorizationException('You are not allowed to change roles.');
        }
        if ($actor->is($target)) {
            throw ValidationException::withMessages(['roles' => 'You cannot change your own roles.']);
        }

        $wanted = array_values(array_unique($roleNames));
        $known = Role::query()->where('guard_name', 'web')->whereIn('name', $wanted)->pluck('name')->all();
        if (count($known) !== count($wanted)) {
            throw ValidationException::withMessages(['roles' => 'Unknown role: '.implode(', ', array_diff($wanted, $known)).'.']);
        }

        $this->db->transaction(function () use ($actor, $target, $wanted): void {
            // Locked, so two concurrent changes cannot both remove the last super admin.
            User::query()->whereKey($target->id)->lockForUpdate()->first();
            $current = $target->roles()->pluck('name')->all();
            $added = array_values(array_diff($wanted, $current));
            $removed = array_values(array_diff($current, $wanted));

            foreach ([...$added, ...$removed] as $role) {
                $this->assertActorMayHandle($actor, $role);
            }
            // The actor is an active super admin too, so this fires only when two super admins remove each other at
            // the same time: the row locks serialise them and the second one sees a single active super admin left.
            if (in_array('super_admin', $removed, true) && $target->status === 'active' && $this->activeSuperAdmins() <= 1) {
                throw ValidationException::withMessages(['roles' => 'The last active super admin cannot lose that role.']);
            }

            $target->syncRoles($wanted);
            foreach ($added as $role) {
                $this->audit->record('role.assigned', $target, ['role' => $role], Actor::user($actor->id), Classification::Internal);
            }
            foreach ($removed as $role) {
                $this->audit->record('role.revoked', $target, ['role' => $role], Actor::user($actor->id), Classification::Internal);
            }
        });
    }

    private function assertActorMayHandle(User $actor, string $role): void
    {
        if ($actor->hasRole('super_admin')) {
            return;
        }
        if ($role === 'super_admin') {
            throw ValidationException::withMessages(['roles' => 'Only a super admin can give or take the super admin role.']);
        }
        $roleGives = Role::findByName($role, 'web')->permissions->pluck('name')->all();
        if (array_diff($roleGives, $actor->getAllPermissions()->pluck('name')->all()) !== []) {
            throw ValidationException::withMessages(['roles' => "You cannot give or take the role [$role]: it has permissions you do not hold."]);
        }
    }

    private function activeSuperAdmins(): int
    {
        return User::role('super_admin')->where('status', 'active')->lockForUpdate()->count();
    }
}
