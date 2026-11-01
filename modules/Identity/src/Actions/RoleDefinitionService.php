<?php

namespace Modules\Identity\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;
use Modules\Identity\Models\User;
use Modules\Platform\Audit\Actor;
use Modules\Platform\Audit\AuditLogger;
use Modules\Platform\Classification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Role definitions (P1-13, docs/07 §3: the matrix is seeded and editable by role.manage). Rules:
 * - the actor is active and holds role.manage (which needs confirmed two-factor, P1-12);
 * - super_admin is fixed: it manages the platform and its permissions are not editable here;
 * - nobody edits a role they hold, so nobody raises their own permissions;
 * - role names are lower-case codes; only existing permissions can be given.
 * Creation and every permission change are audited (role.created, role.permissions_changed).
 */
class RoleDefinitionService
{
    private const NAME = '/^[a-z][a-z0-9_]{1,49}$/';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<string>  $permissions
     */
    public function create(User $actor, string $name, array $permissions): Role
    {
        $this->assertMayManage($actor);
        if (preg_match(self::NAME, $name) !== 1) {
            throw ValidationException::withMessages(['name' => 'Use lower-case letters, digits and underscores (2 to 50 characters), starting with a letter.']);
        }
        if (Role::query()->where('name', $name)->where('guard_name', 'web')->exists()) {
            throw ValidationException::withMessages(['name' => "A role [$name] already exists."]);
        }
        $permissions = $this->knownPermissions($permissions);

        return $this->db->transaction(function () use ($actor, $name, $permissions): Role {
            $role = Role::query()->create(['name' => $name, 'guard_name' => 'web']);
            $role->syncPermissions($permissions);
            $this->audit->record('role.created', $role, ['role' => $name, 'permissions' => $permissions], Actor::user($actor->id), Classification::Internal);

            return $role;
        });
    }

    /**
     * @param  list<string>  $permissions  the complete new set
     */
    public function setPermissions(User $actor, Role $role, array $permissions): void
    {
        $this->assertMayManage($actor);
        if ($role->name === 'super_admin') {
            throw ValidationException::withMessages(['permissions' => 'The super admin role is fixed.']);
        }
        if ($actor->hasRole($role->name)) {
            throw ValidationException::withMessages(['permissions' => 'You cannot change a role you hold.']);
        }
        $permissions = $this->knownPermissions($permissions);

        $this->db->transaction(function () use ($actor, $role, $permissions): void {
            $current = $role->permissions()->pluck('name')->all();
            $added = array_values(array_diff($permissions, $current));
            $removed = array_values(array_diff($current, $permissions));
            if ($added === [] && $removed === []) {
                return;
            }

            $role->syncPermissions($permissions);
            $this->audit->record('role.permissions_changed', $role, ['role' => $role->name, 'added' => $added, 'removed' => $removed], Actor::user($actor->id), Classification::Internal);
        });
    }

    private function assertMayManage(User $actor): void
    {
        if ($actor->status !== 'active' || ! $actor->hasPermissionTo('role.manage')) {
            throw new AuthorizationException('You are not allowed to manage roles.');
        }
    }

    /**
     * @param  list<string>  $permissions
     * @return list<string>
     */
    private function knownPermissions(array $permissions): array
    {
        $permissions = array_values(array_unique($permissions));
        $known = Permission::query()->where('guard_name', 'web')->whereIn('name', $permissions)->pluck('name')->all();
        if (count($known) !== count($permissions)) {
            throw ValidationException::withMessages(['permissions' => 'Unknown permission: '.implode(', ', array_diff($permissions, $known)).'.']);
        }
        sort($permissions);

        return $permissions;
    }
}
