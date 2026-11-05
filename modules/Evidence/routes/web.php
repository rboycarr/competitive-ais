<?php

use Illuminate\Support\Facades\Route;
use Modules\Evidence\Http\Controllers\EvidenceController;

// Evidence (P1-32a). Reading needs evidence.view, uploading evidence.upload; the controller and EvidenceUploadService
// check again (hidden assets are 404). No delete route: assets are rejected or withdrawn, never deleted.
Route::middleware('auth')->group(function () {
    Route::middleware('can:evidence.view')->group(function () {
        Route::get('/evidence', [EvidenceController::class, 'index'])->name('evidence.index');
        Route::get('/evidence/{asset}', [EvidenceController::class, 'show'])->name('evidence.show');
        // P1-34: evidence.download too; EvidenceDownloadService checks again. Rate-limited per user.
        Route::post('/evidence/{asset}/download', [EvidenceController::class, 'download'])
            ->middleware(['can:evidence.download', 'throttle:evidence-downloads'])->name('evidence.download');
    });
    Route::post('/evidence', [EvidenceController::class, 'store'])->middleware('can:evidence.upload')->name('evidence.store');
});
