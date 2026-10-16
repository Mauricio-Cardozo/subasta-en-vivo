<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->configureRateLimiting();
    }

    /**
     * Límites de peticiones del servidor (RNF-06).
     *
     * Los topes salen del balance (config/auction.php) y se aplican por IP: el
     * id de sesión se puede rotar, la IP no. El límite de PIN es el más estricto
     * porque es la única credencial del MVP.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('join', fn (Request $request) => Limit::perMinute(
            config('auction.limits.join_per_minute')
        )->by($request->ip()));

        RateLimiter::for('host-login', fn (Request $request) => Limit::perMinute(
            config('auction.limits.host_login_per_minute')
        )->by($request->ip()));

        RateLimiter::for('lot-opens', fn (Request $request) => Limit::perMinute(
            config('auction.limits.lot_opens_per_minute')
        )->by($request->ip()));
    }
}
