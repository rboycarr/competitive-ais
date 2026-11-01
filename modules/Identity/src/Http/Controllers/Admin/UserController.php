<?php

namespace Modules\Identity\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Identity\Actions\InvitationService;
use Modules\Identity\Actions\RoleAssignmentService;
use Modules\Identity\Http\Requests\Admin\InviteUserRequest;
use Modules\Identity\Http\Requests\Admin\UpdateUserRolesRequest;
use Modules\Identity\Models\Invitation;
use Modules\Identity\Models\User;
use Modules\Platform\Classification;
use Spatie\Permission\Models\Role;

/**
 * Admin › Users (P1-13): the list (user.view), inviting and changing roles (user.manage). Shows account metadata
 * only; never secrets, password hashes or two-factor data beyond on/off.
 */
class UserController
{
    public function index(Request $request): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $canManage = $actor->hasPermissionTo('user.manage');

        return Inertia::render('Identity/Admin/Users', [
            'users' => User::query()->with('roles:id,name')->orderBy('name')->get()->map(fn (User $user): array => [
                'ulid' => $user->ulid,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status,
                'clearance' => $user->clearance->value,
                'roles' => $user->roles->pluck('name')->values()->all(),
                'twoFactor' => $user->hasEnabledTwoFactorAuthentication(),
                'lastLoginAt' => $user->last_login_at?->toIso8601ZuluString(),
                'isSelf' => $user->is($actor),
            ])->all(),
            'invitations' => $canManage ? Invitation::query()->whereNull('accepted_at')->whereNull('revoked_at')
                ->where('expires_at', '>', now('UTC'))->orderBy('email')->get()
                ->map(fn (Invitation $invitation): array => [
                    'ulid' => $invitation->ulid,
                    'email' => $invitation->email,
                    'role' => $invitation->role_name,
                    'clearance' => $invitation->clearance->value,
                    'expiresAt' => $invitation->expires_at->toIso8601ZuluString(),
                ])->all() : [],
            'roles' => Role::query()->where('guard_name', 'web')->orderBy('name')->pluck('name')->all(),
            'clearances' => array_map(fn (Classification $c): string => $c->value, Classification::cases()),
            'canManage' => $canManage,
        ]);
    }

    public function invite(InviteUserRequest $request, InvitationService $invitations): RedirectResponse
    {
        $invitation = $invitations->invite(
            (string) $request->validated('email'),
            (string) $request->validated('role'),
            Classification::from((string) $request->validated('clearance')),
            $this->actor($request),
        );

        return back()->with('status', "Invitation sent to {$invitation->email}.");
    }

    public function updateRoles(UpdateUserRolesRequest $request, User $user, RoleAssignmentService $roles): RedirectResponse
    {
        /** @var list<string> $wanted */
        $wanted = array_values((array) $request->validated('roles'));
        $roles->setRoles($this->actor($request), $user, $wanted);

        return back()->with('status', "Roles of {$user->name} updated.");
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
