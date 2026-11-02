<?php

use Illuminate\Support\Facades\Route;
use Modules\Registry\Http\Controllers\BrandController;
use Modules\Registry\Http\Controllers\CategoryController;
use Modules\Registry\Http\Controllers\ChannelController;
use Modules\Registry\Http\Controllers\CompetitorAliasController;
use Modules\Registry\Http\Controllers\CompetitorController;
use Modules\Registry\Http\Controllers\CompetitorRelationshipController;
use Modules\Registry\Http\Controllers\CompetitorTagController;
use Modules\Registry\Http\Controllers\CurrencyController;
use Modules\Registry\Http\Controllers\MarketController;
use Modules\Registry\Http\Controllers\ProductController;
use Modules\Registry\Http\Controllers\RetailerController;
use Modules\Registry\Http\Controllers\TagController;
use Modules\Registry\Http\Controllers\VariantController;

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
        // Tags (P1-23b): untagging removes the link, audited by the service.
        Route::post('/competitors/{competitor}/tags', [CompetitorTagController::class, 'store'])->name('competitors.tags.store');
        Route::delete('/competitors/{competitor}/tags/{tag}', [CompetitorTagController::class, 'destroy'])->name('competitors.tags.destroy')->whereNumber('tag');
    });

    // Brands (P1-22a): ours and visible competitors'; BrandService checks the owner's visibility again.
    Route::get('/brands', [BrandController::class, 'index'])->middleware('can:product.view')->name('brands.index');
    Route::middleware('can:product.manage')->group(function () {
        Route::post('/brands', [BrandController::class, 'store'])->name('brands.store');
        Route::put('/brands/{brand}', [BrandController::class, 'update'])->name('brands.update');
        Route::delete('/brands/{brand}', [BrandController::class, 'destroy'])->name('brands.destroy');
    });

    // Products and variants (P1-22b): visible like competitors (ProductAccess); retired with a status, never deleted.
    Route::middleware('can:product.view')->group(function () {
        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');
    });
    Route::middleware('can:product.manage')->group(function () {
        Route::post('/products', [ProductController::class, 'store'])->name('products.store');
        Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::post('/products/{product}/variants', [VariantController::class, 'store'])->name('products.variants.store');
        Route::put('/products/{product}/variants/{variant}', [VariantController::class, 'update'])->name('products.variants.update');
    });

    // Admin › Categories (P1-22a), Markets (P1-23a), Currencies, Channels, Retailers and Tags (P1-23b): shared taxonomy
    // for taxonomy.manage holders.
    Route::middleware('can:taxonomy.manage')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::put('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update')->whereNumber('category');
        Route::put('/categories/{category}/parent', [CategoryController::class, 'move'])->name('categories.move')->whereNumber('category');
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy')->whereNumber('category');

        // Markets and their regions (P1-23a). Markets are never deleted.
        Route::get('/markets', [MarketController::class, 'index'])->name('markets.index');
        Route::post('/markets', [MarketController::class, 'store'])->name('markets.store');
        Route::get('/markets/{market}', [MarketController::class, 'show'])->name('markets.show');
        Route::put('/markets/{market}', [MarketController::class, 'update'])->name('markets.update');
        Route::post('/markets/{market}/regions', [MarketController::class, 'storeRegion'])->name('markets.regions.store');
        Route::put('/markets/{market}/regions/{region}', [MarketController::class, 'updateRegion'])->name('markets.regions.update')->whereNumber('region');
        Route::delete('/markets/{market}/regions/{region}', [MarketController::class, 'destroyRegion'])->name('markets.regions.destroy')->whereNumber('region');

        // Currencies are never deleted; channels and tags in use and retailers referred to are kept by their services.
        Route::get('/currencies', [CurrencyController::class, 'index'])->name('currencies.index');
        Route::post('/currencies', [CurrencyController::class, 'store'])->name('currencies.store');
        Route::put('/currencies/{currency}', [CurrencyController::class, 'update'])->name('currencies.update')->where('currency', '[A-Z]{3}');
        Route::get('/channels', [ChannelController::class, 'index'])->name('channels.index');
        Route::post('/channels', [ChannelController::class, 'store'])->name('channels.store');
        Route::put('/channels/{channel}', [ChannelController::class, 'update'])->name('channels.update')->whereNumber('channel');
        Route::delete('/channels/{channel}', [ChannelController::class, 'destroy'])->name('channels.destroy')->whereNumber('channel');
        Route::get('/retailers', [RetailerController::class, 'index'])->name('retailers.index');
        Route::post('/retailers', [RetailerController::class, 'store'])->name('retailers.store');
        Route::put('/retailers/{retailer}', [RetailerController::class, 'update'])->name('retailers.update');
        Route::delete('/retailers/{retailer}', [RetailerController::class, 'destroy'])->name('retailers.destroy');
        Route::get('/tags', [TagController::class, 'index'])->name('tags.index');
        Route::post('/tags', [TagController::class, 'store'])->name('tags.store');
        Route::put('/tags/{tag}', [TagController::class, 'update'])->name('tags.update')->whereNumber('tag');
        Route::delete('/tags/{tag}', [TagController::class, 'destroy'])->name('tags.destroy')->whereNumber('tag');
    });
});
