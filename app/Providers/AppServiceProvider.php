<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
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
     */
    public function boot(): void
    {
        // Guard against N+1 outside production: lazy loads throw so relations
        // must be eager-loaded explicitly (see CLAUDE.md section 3).
        Model::preventLazyLoading(! $this->app->isProduction());
    }
}
