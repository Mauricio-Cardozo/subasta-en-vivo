<?php

namespace App\Providers;

use App\Services\SessionIdentity;
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
     * Los topes salen del balance (config/auction.php). Los límites de entrada
     * (alta y PIN) se aplican por IP, que no se puede rotar. Las pujas se
     * limitan por pujador (la misma persona detrás de una IP compartida no se
     * bloquea entre sí) y caen a la IP si todavía no hay identidad.
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

        RateLimiter::for('lot-closes', fn (Request $request) => Limit::perMinute(
            config('auction.limits.lot_closes_per_minute')
        )->by($request->ip()));

        RateLimiter::for('bids', function (Request $request) {
            $participant = $request->session()->get(SessionIdentity::PARTICIPANT_KEY);

            return Limit::perSecond(config('auction.limits.bids_per_second'))
                ->by($participant === null ? 'ip:'.$request->ip() : 'participant:'.$participant);
        });
    }
}
