<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register PDF Services
        $this->app->singleton(\App\Services\PDFService::class, function ($app) {
            return new \App\Services\PDFService();
        });

        $this->app->singleton(\App\Services\ConversionService::class, function ($app) {
            return new \App\Services\ConversionService();
        });

        $this->app->singleton(\App\Services\OCRService::class, function ($app) {
            return new \App\Services\OCRService();
        });

        $this->app->singleton(\App\Services\PdfToHtmlService::class, function ($app) {
            return new \App\Services\PdfToHtmlService();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Register policies
        Gate::policy(\App\Models\Document::class, \App\Policies\DocumentPolicy::class);
        Gate::policy(\App\Models\User::class, \App\Policies\UserPolicy::class);
        Gate::policy(\App\Models\Tenant::class, \App\Policies\TenantPolicy::class);
        Gate::policy(\App\Models\Conversion::class, \App\Policies\ConversionPolicy::class);
        Gate::policy(\Spatie\Permission\Models\Role::class, \App\Policies\RolePolicy::class);

        // Implicit gate for super admin
        Gate::before(function ($user, $ability) {
            if ($user->isSuperAdmin()) {
                return true;
            }
        });
    }
}
