<?php

namespace App\Providers;

use App\Services\SessionIdentity;
use App\Support\Broadcasting\ResilientBroadcastManager;
use App\Support\CorrelationId;
use App\Support\RequestContext;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Estado por petición: el `correlation_id` y la IP que comparten el
        // middleware, la auditoría y los logs.
        $this->app->singleton(CorrelationId::class);
        $this->app->singleton(RequestContext::class);

        // La difusión es best-effort: el emisor queda envuelto para que una
        // caída de Reverb se registre y no rompa una acción ya confirmada. Va
        // con `extend` y no con un binding propio: `BroadcastServiceProvider`
        // es diferido y registra el manager recién al resolverlo, después de
        // este `register`, así que el extender es el único que corre al final.
        $this->app->extend(
            BroadcastManager::class,
            fn (BroadcastManager $manager, Application $app): ResilientBroadcastManager => new ResilientBroadcastManager($app),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->logSlowQueries();
    }

    /**
     * Consultas lentas al canal `sql` (RF-17).
     *
     * Se registra el SQL con sus `?`, nunca los valores: pueden traer datos de
     * los usuarios. El `correlation_id` llega por el contexto compartido, así
     * que una consulta lenta se ubica en la petición que la hizo.
     */
    private function logSlowQueries(): void
    {
        DB::listen(function (QueryExecuted $query): void {
            if ($query->time < (float) config('auction.sql.slow_ms')) {
                return;
            }

            Log::channel(config('auction.sql.log_channel'))->warning('sql.slow', [
                'sql' => $query->sql,
                'ms' => $query->time,
                'connection' => $query->connectionName,
            ]);
        });
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

        RateLimiter::for('lot-deletes', fn (Request $request) => Limit::perMinute(
            config('auction.limits.lot_deletes_per_minute')
        )->by($request->ip()));

        RateLimiter::for('bids', function (Request $request) {
            $participant = $request->session()->get(SessionIdentity::PARTICIPANT_KEY);

            return Limit::perSecond(config('auction.limits.bids_per_second'))
                ->by($participant === null ? 'ip:'.$request->ip() : 'participant:'.$participant);
        });

        // El chat se limita por quien escribe: el pujador por su identidad y el
        // subastador (sin participante) por su IP.
        RateLimiter::for('chat-messages', function (Request $request) {
            $participant = $request->session()->get(SessionIdentity::PARTICIPANT_KEY);

            return Limit::perSecond(config('auction.limits.chat_messages_per_second'))
                ->by($participant === null ? 'ip:'.$request->ip() : 'participant:'.$participant);
        });
    }
}
