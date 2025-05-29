<?php

namespace App\Providers;

use App\Models\PriceScheme;
use App\Models\User;
use App\Observers\PriceSchemeObserver;
use App\Observers\UserObserver;
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
        $this->app['router']->aliasMiddleware('role', \App\Http\Middleware\CheckRole::class);
        PriceScheme::observe(PriceSchemeObserver::class);
        User::observe(UserObserver::class);
    }
}
