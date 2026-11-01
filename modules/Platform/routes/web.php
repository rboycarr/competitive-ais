<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Placeholder start page until the dashboard (P1-46) replaces it.
Route::get('/', fn () => Inertia::render('Platform/Home'))->name('home');
