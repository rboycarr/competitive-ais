<?php

use Illuminate\Support\Facades\Route;
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
