<?php

namespace App\Providers;

use App\Services\AuditService;
use App\Support\Settings;
use App\Support\ThemeStyles;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One instance per request, so that turning the trail off inside
        // `withoutAuditing()` also reaches the model observer.
        $this->app->singleton(AuditService::class);

        // One instance per request, so the cached settings array is read once.
        $this->app->singleton(Settings::class);
        $this->app->singleton(ThemeStyles::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
