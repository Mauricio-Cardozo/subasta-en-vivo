<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Actualización de la subasta que ya fue persistida.
 *
 * Representa solo transiciones confirmadas (apertura de lote, puja aceptada,
 * cierre): nunca intentos ni rechazos. Como el canal `auction` es público, el
 * snapshot se filtra a PUBLIC_KEYS y los bloques anidados (`lot`, `bids`) los
 * construye el serializador de la subasta con campos públicos únicamente: la IP
 * y el id de sesión de quien disparó la acción viajan solo a los logs y a
 * `auction_actions`.
 */
class AuctionStateUpdated implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * Claves que pueden mostrarse a cualquiera que escuche el canal público.
     *
     * @var list<string>
     */
    public const PUBLIC_KEYS = [
        'version',
        'lot',
        'bids',
        'action',
        'server_now',
        'correlation_id',
    ];

    /**
     * @var array<string, mixed>
     */
    public readonly array $snapshot;

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function __construct(array $snapshot)
    {
        $this->snapshot = array_intersect_key($snapshot, array_flip(self::PUBLIC_KEYS));
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('auction')];
    }

    public function broadcastAs(): string
    {
        return 'AuctionStateUpdated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->snapshot;
    }
}
