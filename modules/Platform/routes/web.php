<?php

use Illuminate\Support\Facades\Route;
use Modules\Platform\Http\Controllers\DashboardController;

// The start page: the dashboard for signed-in users (P1-46), the sign-in link for guests.
Route::get('/', DashboardController::class)->name('home');
