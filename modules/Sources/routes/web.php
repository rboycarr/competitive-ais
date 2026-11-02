<?php

use Illuminate\Support\Facades\Route;
use Modules\Sources\Http\Controllers\SourceController;

// Sources (P1-30). Reading needs source.view, proposing source.propose; SourceService and AccessScope check again
// (hidden sources are 404). No delete route: sources are retired.
Route::middleware('auth')->group(function () {
    Route::middleware('can:source.view')->group(function () {
        Route::get('/sources', [SourceController::class, 'index'])->name('sources.index');
        Route::get('/sources/{source}', [SourceController::class, 'show'])->name('sources.show');
    });
    Route::post('/sources', [SourceController::class, 'store'])->middleware('can:source.propose')->name('sources.store');
});
