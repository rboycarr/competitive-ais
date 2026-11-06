<?php

use Illuminate\Support\Facades\Route;
use Modules\Intelligence\Http\Controllers\IntelligenceClaimController;
use Modules\Intelligence\Http\Controllers\IntelligenceItemController;
use Modules\Intelligence\Http\Controllers\VerificationQueueController;

// Intelligence (P1-40). Reading needs intelligence.view (and the item's visibility: hidden items are 404), writing
// intelligence.create; the services check again, including author/owner, editable status and the version read.
// No delete routes: items are never deleted and claims are withdrawn.
Route::middleware('auth')->group(function () {
    Route::middleware('can:intelligence.view')->group(function () {
        Route::get('/intelligence', [IntelligenceItemController::class, 'index'])->name('intelligence.index');
        Route::get('/intelligence/{item}', [IntelligenceItemController::class, 'show'])->name('intelligence.show');
    });
    Route::middleware('can:intelligence.create')->group(function () {
        Route::post('/intelligence', [IntelligenceItemController::class, 'store'])->name('intelligence.store');
        Route::put('/intelligence/{item}', [IntelligenceItemController::class, 'update'])->name('intelligence.update');
        Route::post('/intelligence/{item}/claims', [IntelligenceClaimController::class, 'store'])->name('intelligence.claims.store');
        Route::put('/intelligence/{item}/claims/{claim}', [IntelligenceClaimController::class, 'update'])->name('intelligence.claims.update');
        Route::post('/intelligence/{item}/claims/{claim}/withdraw', [IntelligenceClaimController::class, 'withdraw'])->name('intelligence.claims.withdraw');
        Route::post('/intelligence/{item}/claims/{claim}/move', [IntelligenceClaimController::class, 'move'])->name('intelligence.claims.move');
        // P1-41: a claim cites evidence, or stops citing it (while the item is editable).
        Route::post('/intelligence/{item}/claims/{claim}/evidence', [IntelligenceClaimController::class, 'link'])->name('intelligence.claims.link');
        Route::post('/intelligence/{item}/claims/{claim}/evidence/remove', [IntelligenceClaimController::class, 'unlink'])->name('intelligence.claims.unlink');
    });
    // P1-42: the workflow. Only intelligence.view here: IntelligenceWorkflowService checks visibility first (a hidden
    // item is 404 for everyone, TASK-0087 #1), then intelligence.submit, the actor, the item's state and its guard.
    Route::post('/intelligence/{item}/submit', [IntelligenceItemController::class, 'submit'])->middleware('can:intelligence.view')->name('intelligence.submit');
    // P1-43: the verification queue and its transitions, the same way: the service checks visibility first, then
    // intelligence.publish (assigning) or intelligence.review and the assignment (reviewing).
    Route::middleware('can:intelligence.view')->group(function () {
        Route::get('/verification', [VerificationQueueController::class, 'index'])->name('verification.index');
        Route::post('/intelligence/{item}/assign', [VerificationQueueController::class, 'assign'])->name('intelligence.assign');
        Route::post('/intelligence/{item}/review', [VerificationQueueController::class, 'start'])->name('intelligence.review.start');
        Route::post('/intelligence/{item}/decision', [VerificationQueueController::class, 'decide'])->name('intelligence.decide');
        // P1-44: publication, retraction, and a replacement draft (the service checks intelligence.create).
        Route::post('/intelligence/{item}/publish', [IntelligenceItemController::class, 'publish'])->name('intelligence.publish');
        Route::post('/intelligence/{item}/retract', [IntelligenceItemController::class, 'retract'])->name('intelligence.retract');
        Route::post('/intelligence/{item}/replacement', [IntelligenceItemController::class, 'replacement'])->name('intelligence.replacement');
    });
});
