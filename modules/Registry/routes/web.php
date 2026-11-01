<?php

use Illuminate\Support\Facades\Route;
use Modules\Registry\Http\Controllers\CompetitorAliasController;
use Modules\Registry\Http\Controllers\CompetitorController;
use Modules\Registry\Http\Controllers\CompetitorRelationshipController;

// Competitors (P1-20). Reading needs competitor.view, changes competitor.manage; the service and AccessScope check
// again (hidden entries are 404). No delete route for competitors: they are archived.
Route::middleware('auth')->group(function () {
    Route::middleware('can:competitor.view')->group(function () {
        Route::get('/competitors', [CompetitorController::class, 'index'])->name('competitors.index');
        Route::get('/competitors/{competitor}', [CompetitorController::class, 'show'])->name('competitors.show');
    });
    Route::middleware('can:competitor.manage')->group(function () {
        Route::post('/competitors', [CompetitorController::class, 'store'])->name('competitors.store');
        Route::put('/competitors/{competitor}', [CompetitorController::class, 'update'])->name('competitors.update');
        Route::put('/competitors/{competitor}/lifecycle', [CompetitorController::class, 'lifecycle'])->name('competitors.lifecycle');
        // Aliases and relationships (P1-21): removed rows are corrections, audited by the services.
        Route::post('/competitors/{competitor}/aliases', [CompetitorAliasController::class, 'store'])->name('competitors.aliases.store');
        Route::delete('/competitors/{competitor}/aliases/{alias}', [CompetitorAliasController::class, 'destroy'])->name('competitors.aliases.destroy')->whereNumber('alias');
        Route::post('/competitors/{competitor}/relationships', [CompetitorRelationshipController::class, 'store'])->name('competitors.relationships.store');
        Route::put('/competitors/{competitor}/relationships/{relationship}', [CompetitorRelationshipController::class, 'update'])->name('competitors.relationships.update');
        Route::delete('/competitors/{competitor}/relationships/{relationship}', [CompetitorRelationshipController::class, 'destroy'])->name('competitors.relationships.destroy');
    });
});
