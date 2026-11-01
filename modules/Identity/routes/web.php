<?php

use Illuminate\Support\Facades\Route;
use Modules\Identity\Http\Controllers\Admin\AccessGrantController;
use Modules\Identity\Http\Controllers\Admin\RoleController;
use Modules\Identity\Http\Controllers\Admin\TeamController;
use Modules\Identity\Http\Controllers\Admin\UserController;
use Modules\Identity\Http\Controllers\InvitationController;
use Modules\Identity\Http\Controllers\SecurityController;

// Accepting an invitation (P1-10). Rate-limited per IP (IdentityServiceProvider). No route pattern on {token}: the
// service rejects malformed tokens, so every unusable link gets the same page (TASK-0017 #3).
Route::middleware('throttle:invitations')->group(function () {
    Route::get('/invitations/accepted', [InvitationController::class, 'accepted'])->name('invitations.accepted');
    Route::get('/invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
    Route::post('/invitations/{token}', [InvitationController::class, 'accept'])->name('invitations.accept');
});

// Security settings (P1-11), behind a recent password confirmation, so the two-factor endpoints on the page (Fortify's
// routes, config/fortify.php) do not interrupt with their own confirmation. Login and logout are Fortify's routes.
Route::middleware(['auth', 'password.confirm'])->get('/user/security', [SecurityController::class, 'show'])->name('security.show');

// Administration (P1-13). Each route needs the permission; the services check the actor again.
Route::middleware('auth')->prefix('admin')->name('admin.')->group(function () {
    Route::get('/users', [UserController::class, 'index'])->middleware('can:user.view')->name('users.index');
    Route::post('/users/invitations', [UserController::class, 'invite'])->middleware('can:user.manage')->name('users.invite');
    Route::put('/users/{user}/roles', [UserController::class, 'updateRoles'])->middleware('can:user.manage')->name('users.roles');

    Route::get('/roles', [RoleController::class, 'index'])->middleware('can:role.manage')->name('roles.index');
    Route::post('/roles', [RoleController::class, 'store'])->middleware('can:role.manage')->name('roles.store');
    Route::put('/roles/{role}/permissions', [RoleController::class, 'updatePermissions'])->middleware('can:role.manage')->name('roles.permissions');

    Route::middleware('can:team.manage')->group(function () {
        Route::get('/teams', [TeamController::class, 'index'])->name('teams.index');
        Route::post('/teams', [TeamController::class, 'store'])->name('teams.store');
        Route::put('/teams/{team}', [TeamController::class, 'update'])->name('teams.update');
        Route::post('/teams/{team}/members', [TeamController::class, 'addMember'])->name('teams.members.add');
        Route::delete('/teams/{team}/members/{user}', [TeamController::class, 'removeMember'])->name('teams.members.remove');
    });

    // Access grants (P1-14): revoked, never deleted, so there is no DELETE route.
    Route::middleware('can:grant.manage')->group(function () {
        Route::get('/access-grants', [AccessGrantController::class, 'index'])->name('access-grants.index');
        Route::post('/access-grants', [AccessGrantController::class, 'store'])->name('access-grants.store');
        Route::post('/access-grants/{grant}/revoke', [AccessGrantController::class, 'revoke'])->name('access-grants.revoke');
    });
});
