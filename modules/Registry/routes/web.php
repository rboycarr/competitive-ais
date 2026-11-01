<?php

use Illuminate\Support\Facades\Route;
use Modules\Registry\Http\Controllers\BrandController;
use Modules\Registry\Http\Controllers\CategoryController;
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

    // Brands (P1-22a): ours and visible competitors'; BrandService checks the owner's visibility again.
    Route::get('/brands', [BrandController::class, 'index'])->middleware('can:product.view')->name('brands.index');
    Route::middleware('can:product.manage')->group(function () {
        Route::post('/brands', [BrandController::class, 'store'])->name('brands.store');
        Route::put('/brands/{brand}', [BrandController::class, 'update'])->name('brands.update');
        Route::delete('/brands/{brand}', [BrandController::class, 'destroy'])->name('brands.destroy');
    });

    // Admin › Categories (P1-22a): the product category tree.
    Route::middleware('can:taxonomy.manage')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::put('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update')->whereNumber('category');
        Route::put('/categories/{category}/parent', [CategoryController::class, 'move'])->name('categories.move')->whereNumber('category');
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy')->whereNumber('category');
    });
});
