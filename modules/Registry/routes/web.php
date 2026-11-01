<?php

use Illuminate\Support\Facades\Route;
use Modules\Registry\Http\Controllers\CompetitorController;

// Competitors (P1-20). Reading needs competitor.view, changes competitor.manage; the service and AccessScope check
// again (hidden entries are 404). No delete route: competitors are archived.
Route::middleware('auth')->group(function () {
    Route::middleware('can:competitor.view')->group(function () {
        Route::get('/competitors', [CompetitorController::class, 'index'])->name('competitors.index');
        Route::get('/competitors/{competitor}', [CompetitorController::class, 'show'])->name('competitors.show');
    });
    Route::middleware('can:competitor.manage')->group(function () {
        Route::post('/competitors', [CompetitorController::class, 'store'])->name('competitors.store');
        Route::put('/competitors/{competitor}', [CompetitorController::class, 'update'])->name('competitors.update');
        Route::put('/competitors/{competitor}/lifecycle', [CompetitorController::class, 'lifecycle'])->name('competitors.lifecycle');
    });
});
