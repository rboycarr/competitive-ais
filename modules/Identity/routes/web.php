<?php

use Illuminate\Support\Facades\Route;
use Modules\Identity\Http\Controllers\InvitationController;

// Accepting an invitation (P1-10). Rate-limited per IP (IdentityServiceProvider); tokens are 64 hex characters.
Route::middleware('throttle:invitations')->group(function () {
    Route::get('/invitations/accepted', [InvitationController::class, 'accepted'])->name('invitations.accepted');
    Route::get('/invitations/{token}', [InvitationController::class, 'show'])->where('token', '[0-9a-f]{64}')->name('invitations.show');
    Route::post('/invitations/{token}', [InvitationController::class, 'accept'])->where('token', '[0-9a-f]{64}')->name('invitations.accept');
});
