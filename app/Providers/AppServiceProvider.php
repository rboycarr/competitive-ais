<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * Domain code lives in modules/ (ADR-0001); platform wiring is in Modules\Platform\PlatformServiceProvider.
     */
    public function boot(): void
    {
        //
    }
}
