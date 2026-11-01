<?php

namespace Modules\Identity\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Identity\Actions\RoleDefinitionService;
use Modules\Identity\Http\Requests\Admin\RoleRequest;
use Modules\Identity\MfaRequirement;
use Modules\Identity\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** Admin › Roles (P1-13, role.manage): the role and permission matrix, new roles, and permission changes. */
class RoleController
{
    public function index(Request $request): Response
    {
        $actor = $this->actor($request);

        return Inertia::render('Identity/Admin/Roles', [
            'roles' => Role::query()->where('guard_name', 'web')->with('permissions:id,name')->withCount('users')->orderBy('name')->get()
                ->map(fn (Role $role): array => [
                    'name' => $role->name,
                    'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
                    'members' => (int) $role->getAttribute('users_count'),
                    'editable' => $role->name !== 'super_admin' && ! $actor->hasRole($role->name),
                ])->all(),
            'permissions' => Permission::query()->where('guard_name', 'web')->orderBy('name')->pluck('name')->all(),
            'privileged' => MfaRequirement::PRIVILEGED_PERMISSIONS,
        ]);
    }

    public function store(RoleRequest $request, RoleDefinitionService $roles): RedirectResponse
    {
        /** @var list<string> $permissions */
        $permissions = array_values((array) $request->validated('permissions'));
        $role = $roles->create($this->actor($request), (string) $request->validated('name'), $permissions);

        return back()->with('status', "Role {$role->name} created.");
    }

    public function updatePermissions(RoleRequest $request, string $role, RoleDefinitionService $roles): RedirectResponse
    {
        $model = Role::query()->where('guard_name', 'web')->where('name', $role)->firstOrFail();
        /** @var list<string> $permissions */
        $permissions = array_values((array) $request->validated('permissions'));
        $roles->setPermissions($this->actor($request), $model, $permissions);

        return back()->with('status', "Permissions of {$model->name} updated.");
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
