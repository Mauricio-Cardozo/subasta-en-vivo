<?php

namespace App\Support\Broadcasting;

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Difusión a prueba de caídas (Tarea 1) y auditada (RF-16).
 *
 * Cuando el evento sale a la red, la acción HTTP ya está confirmada y el
 * `action_id` guardado: si Reverb está caído, el fallo queda como una línea en
 * el canal de logs (con el `correlation_id` del contexto compartido) y la
 * respuesta no cambia. La autoridad sigue siendo el endpoint de estado: el
 * WebSocket solo anuncia.
 */
class ResilientBroadcaster implements Broadcaster
{
    public function __construct(private readonly Broadcaster $broadcaster) {}

    public function auth($request)
    {
        return $this->broadcaster->auth($request);
    }

    public function validAuthenticationResponse($request, $result)
    {
        return $this->broadcaster->validAuthenticationResponse($request, $result);
    }

    public function broadcast(array $channels, $event, array $payload = [])
    {
        try {
            $this->broadcaster->broadcast($channels, $event, $payload);
        } catch (Throwable $exception) {
            $this->report($channels, (string) $event, $exception);

            return;
        }

        $this->sent($channels, (string) $event, $payload);
    }

    /**
     * Lo demás pasa tal cual al broadcaster real: `BroadcastManager` reenvía
     * por `__call` cosas como `channel()` (registro de `routes/channels.php`),
     * que el decorador no debe interceptar.
     *
     * @param  array<int, mixed>  $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->broadcaster->{$method}(...$parameters);
    }

    /**
     * Una línea por evento que Reverb aceptó (RF-16): evento, canales, payload
     * y origen. Es "entregado a Reverb", no "recibido por los navegadores":
     * Reverb no confirma la entrega a cada cliente.
     *
     * @param  array<int, mixed>  $channels
     * @param  array<string, mixed>  $payload
     */
    private function sent(array $channels, string $event, array $payload): void
    {
        try {
            Log::channel(config('auction.broadcast.log_channel'))->info('broadcast.sent', [
                'event' => $event,
                'channels' => array_map(static fn ($channel): string => (string) $channel, $channels),
                // `socket` lo agrega Laravel para excluir al emisor; no es dato.
                'payload' => array_diff_key($payload, ['socket' => true]),
                'origin' => app()->runningInConsole() ? 'console' : request()->method().' '.request()->path(),
            ]);
        } catch (Throwable) {
            // El log es diagnóstico: nunca rompe la difusión.
        }
    }

    /**
     * Deja una sola línea JSON por fallo. Ni siquiera un logger caído puede
     * romper la acción: si el registro falla, se descarta.
     *
     * @param  array<int, mixed>  $channels
     */
    private function report(array $channels, string $event, Throwable $exception): void
    {
        try {
            Log::channel(config('auction.broadcast.log_channel'))->error('broadcast.failed', [
                'event' => $event,
                'channels' => array_map(static fn ($channel): string => (string) $channel, $channels),
                'exception' => $exception->getMessage(),
                'exception_class' => $exception::class,
            ]);
        } catch (Throwable) {
            // El WebSocket nunca es la autoridad: sin log, la acción sigue.
        }
    }
}
