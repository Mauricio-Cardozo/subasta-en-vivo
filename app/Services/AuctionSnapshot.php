<?php

namespace App\Services;

use App\Models\AuctionRoom;
use App\Models\Lot;
use App\Models\Participant;

/**
 * Construye el snapshot público del estado de la subasta (5.4).
 *
 * Es la única fuente de verdad del payload: lo usan tanto el evento
 * `AuctionStateUpdated` como `GET /auction/state`, así el navegador nunca ve
 * dos formas distintas del mismo estado.
 *
 * Solo viajan campos públicos: ids internos de participantes, IPs y saldos
 * ajenos quedan afuera. El saldo propio se agrega únicamente en la respuesta
 * HTTP (nunca en el broadcast).
 */
class AuctionSnapshot
{
    public function __construct(private readonly ?AuctionRoom $room) {}

    /**
     * Snapshot que puede publicarse en el canal público.
     *
     * @param  array{type: string, summary: string}|null  $action
     * @return array<string, mixed>
     */
    public function build(?array $action = null, ?string $correlationId = null): array
    {
        return [
            'version' => $this->version(),
            // El cliente usa esta marca para su cuenta regresiva: no confía en
            // el reloj del navegador (RF-08).
            'server_now' => now()->toIso8601String(),
            'lot' => $this->lot(),
            // Las pujas llegan en el Hito 3.
            'bids' => [],
            'action' => $action,
            'correlation_id' => $correlationId,
        ];
    }

    /**
     * Snapshot para quien está mirando la pantalla: agrega su propio estado.
     *
     * @param  array{type: string, summary: string}|null  $action
     * @return array<string, mixed>
     */
    public function forViewer(?Participant $viewer, bool $isHost, ?array $action = null): array
    {
        $state = $this->build($action);
        $lot = $this->currentLot();

        $state['me'] = [
            'name' => $viewer?->name,
            'balance_cents' => $viewer?->balance_cents,
            'is_host' => $isHost,
            'is_leader' => $viewer !== null && $lot?->leader_participant_id === $viewer->id,
        ];

        return $state;
    }

    public function version(): int
    {
        return $this->room->version ?? 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lot(): ?array
    {
        $lot = $this->currentLot();

        if (! $lot instanceof Lot) {
            return null;
        }

        return [
            'id' => $lot->id,
            'title' => $lot->title,
            'description' => $lot->description,
            'status' => $lot->status->value,
            'start_price_cents' => $lot->start_price_cents,
            'min_increment_cents' => $lot->min_increment_cents,
            'current_price_cents' => $lot->current_price_cents,
            // Precio de partida para la próxima puja (4.1).
            'reference_price_cents' => $lot->referencePriceCents(),
            'leader_name' => $lot->leader?->name,
            'winner_name' => $lot->winner?->name,
            'ends_at' => $lot->ends_at->toIso8601String(),
            'remaining_ms' => max(0, $lot->ends_at->getTimestampMs() - now()->getTimestampMs()),
            'extensions' => $lot->extensions,
            'closed_at' => $lot->closed_at?->toIso8601String(),
        ];
    }

    private function currentLot(): ?Lot
    {
        return $this->room?->currentLot;
    }
}
