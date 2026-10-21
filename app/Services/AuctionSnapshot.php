<?php

namespace App\Services;

use App\Enums\LotStatus;
use App\Models\AuctionRoom;
use App\Models\Bid;
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
            // Últimas pujas del lote abierto, de la más nueva a la más vieja
            // (RF-10). El cliente ya conoce el resto por el lote.
            'bids' => $this->bids(),
            // Lotes ya cerrados, del más nuevo al más viejo (RF-13).
            'history' => $this->history(),
            // Lotes esperando turno y la pausa hacia el siguiente (Tarea 2).
            'queue' => $this->queue(),
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

    /**
     * Lotes cerrados, del más nuevo al más viejo (RF-13).
     *
     * Igual que el resto del snapshot, solo viajan campos públicos: título,
     * estado, precio final, nombre del ganador y cuándo cerró. Ni ids internos,
     * ni sesiones, ni saldos (RNF-07). `final_price_cents` es el precio al que
     * quedó el lote (null si nunca tuvo pujas). Los lotes en cola y los quitados
     * no son historial: solo lo vendido y lo que quedó sin vender.
     *
     * @return list<array<string, mixed>>
     */
    private function history(): array
    {
        $limit = (int) config('auction.history.limit');

        if (! $this->room instanceof AuctionRoom || $limit < 1) {
            return [];
        }

        return $this->room->lots()
            ->whereIn('status', [LotStatus::Sold->value, LotStatus::Unsold->value])
            ->with('winner')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (Lot $lot): array => [
                'title' => $lot->title,
                'status' => $lot->status->value,
                'final_price_cents' => $lot->current_price_cents,
                'winner_name' => $lot->winner?->name,
                'closed_at' => $lot->closed_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Cola de lotes que esperan turno (Tarea 2).
     *
     * `opens_at` marca cuándo abre el siguiente lote (la pausa posterior a un
     * cierre) y `opens_in_ms` lo expresa contra la marca del servidor, igual
     * que `remaining_ms` del lote (RF-08): el navegador no calcula con su
     * reloj. Los items muestran solo título y precio base; la cola está
     * acotada por `auction.queue.max`.
     *
     * @return array{opens_at: string|null, opens_in_ms: int|null, items: list<array<string, mixed>>}
     */
    private function queue(): array
    {
        $opensAt = $this->room?->queue_opens_at;

        return [
            'opens_at' => $opensAt?->toIso8601String(),
            'opens_in_ms' => $opensAt === null
                ? null
                : max(0, $opensAt->getTimestampMs() - now()->getTimestampMs()),
            'items' => $this->room instanceof AuctionRoom ? $this->queuedLots($this->room) : [],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function queuedLots(AuctionRoom $room): array
    {
        return $room->lots()
            ->where('status', LotStatus::Queued->value)
            ->orderBy('id')
            ->limit((int) config('auction.queue.max'))
            ->get()
            ->map(fn (Lot $lot): array => [
                'id' => $lot->id,
                'title' => $lot->title,
                'start_price_cents' => $lot->start_price_cents,
            ])
            ->all();
    }

    private function currentLot(): ?Lot
    {
        return $this->room?->currentLot;
    }

    /**
     * Últimas pujas del lote, de la más reciente a la más antigua.
     *
     * Solo viajan el nombre del autor, el monto y el momento: ni el id del
     * participante ni su sesión ni su saldo salen de acá (RNF-07).
     *
     * @return list<array<string, mixed>>
     */
    private function bids(): array
    {
        $lot = $this->currentLot();

        if (! $lot instanceof Lot) {
            return [];
        }

        return $lot->bids()
            ->with('participant')
            ->orderByDesc('id')
            ->limit(config('auction.snapshot.bids'))
            ->get()
            ->map(fn (Bid $bid): array => [
                'author_name' => $bid->participant?->name,
                'amount_cents' => $bid->amount_cents,
                'at' => $bid->created_at?->toIso8601String(),
            ])
            ->all();
    }
}
