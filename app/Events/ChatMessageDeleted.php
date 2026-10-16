<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Un mensaje de chat borrado (RF-11, moderación del subastador).
 *
 * Va por el canal público `auction` y lleva solo el id del mensaje: los clientes
 * lo quitan de la lista. El borrado es físico, así que al recargar tampoco
 * aparece.
 */
class ChatMessageDeleted implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public readonly int $messageId) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('auction')];
    }

    public function broadcastAs(): string
    {
        return 'ChatMessageDeleted';
    }

    /**
     * @return array{id: int}
     */
    public function broadcastWith(): array
    {
        return ['id' => $this->messageId];
    }
}
