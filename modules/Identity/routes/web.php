<?php

use Illuminate\Support\Facades\Route;
use Modules\Identity\Http\Controllers\InvitationController;

// Accepting an invitation (P1-10). Rate-limited per IP (IdentityServiceProvider). No route pattern on {token}: the
// service rejects malformed tokens, so every unusable link gets the same page (TASK-0017 #3).
Route::middleware('throttle:invitations')->group(function () {
    Route::get('/invitations/accepted', [InvitationController::class, 'accepted'])->name('invitations.accepted');
    Route::get('/invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
    Route::post('/invitations/{token}', [InvitationController::class, 'accept'])->name('invitations.accept');
});
