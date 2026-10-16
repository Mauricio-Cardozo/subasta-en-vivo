<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Un mensaje de chat ya persistido (RF-11).
 *
 * Va por el canal público `auction`, igual que el estado: el payload se arma en
 * el servicio y solo lleva datos públicos (id, autor, texto y momento), nunca
 * ids de sesión ni la IP. El chat no entra en `version`: se ordena por `id`
 * (RF-12).
 */
class ChatMessagePosted implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  array{id: int, author_name: string, body: string, posted_at: string}  $message
     */
    public function __construct(public readonly array $message) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('auction')];
    }

    public function broadcastAs(): string
    {
        return 'ChatMessagePosted';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->message;
    }
}
