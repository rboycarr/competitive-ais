<?php

use Illuminate\Support\Facades\Route;
use Modules\Sources\Http\Controllers\SourceController;
use Modules\Sources\Http\Controllers\SourceLifecycleController;
use Modules\Sources\Http\Controllers\SourcePolicyController;

// Sources (P1-30). Reading needs source.view, proposing and submitting source.propose, deciding a policy source.approve
// (P1-31a), suspending, reinstating and retiring source.manage (P1-31b); the services and AccessScope check again
// (hidden sources are 404). No delete route: sources are retired.
Route::middleware('auth')->group(function () {
    Route::middleware('can:source.view')->group(function () {
        Route::get('/sources', [SourceController::class, 'index'])->name('sources.index');
        Route::get('/sources/{source}', [SourceController::class, 'show'])->name('sources.show');
    });
    Route::post('/sources', [SourceController::class, 'store'])->middleware('can:source.propose')->name('sources.store');
    Route::post('/sources/{source}/submit', [SourcePolicyController::class, 'submit'])->middleware('can:source.propose')->name('sources.submit');
    Route::post('/sources/{source}/policies', [SourcePolicyController::class, 'store'])->middleware('can:source.approve')->name('sources.policies.store');
    Route::post('/sources/{source}/status', [SourceLifecycleController::class, 'store'])->middleware('can:source.manage')->name('sources.status.store');
});
