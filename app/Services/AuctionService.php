<?php

namespace App\Services;

use App\Enums\LotStatus;
use App\Events\AuctionStateUpdated;
use App\Models\AuctionRoom;
use App\Models\Bid;
use App\Models\Lot;
use App\Models\Participant;
use App\Support\ActionResult;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Única autoridad de la subasta (regla de oro del proyecto).
 *
 * Toda acción que modifica estado: (1) abre una transacción, (2) bloquea la fila
 * de la sala con `lockForUpdate` para serializar el acceso, (3) valida y
 * persiste, (4) sube `version` una sola vez y (5) publica el evento, que sale
 * después del commit porque `AuctionStateUpdated` implementa
 * `ShouldDispatchAfterCommit`.
 */
class AuctionService
{
    /**
     * Abre un lote, si no hay otro abierto (RF-01).
     */
    public function openLot(
        string $title,
        ?string $description,
        int $startPriceCents,
        int $minIncrementCents,
        int $durationSeconds,
    ): ActionResult {
        return DB::transaction(function () use ($title, $description, $startPriceCents, $minIncrementCents, $durationSeconds): ActionResult {
            $room = $this->lockRoom();

            // Cierre perezoso: si el lote anterior ya venció, se liquida antes de
            // decidir si se puede abrir otro (no hay cron; el vencimiento se
            // resuelve dentro de cada acción).
            $this->closeLotIfDue($room);

            if ($this->hasOpenLot($room)) {
                return ActionResult::rejected(
                    'lot_already_open',
                    'Ya hay un lote abierto: cerralo antes de abrir otro.'
                );
            }

            $now = now();

            $lot = Lot::query()->create([
                'room_id' => $room->id,
                'title' => $title,
                'description' => $description,
                'start_price_cents' => $startPriceCents,
                'min_increment_cents' => $minIncrementCents,
                'current_price_cents' => null,
                'status' => LotStatus::Open,
                'started_at' => $now,
                'ends_at' => $now->copy()->addSeconds($durationSeconds),
            ]);

            // El puntero y el estado del lote se actualizan en la misma
            // transacción: no pueden desincronizarse.
            $room->forceFill([
                'current_lot_id' => $lot->id,
                'version' => $room->version + 1,
            ])->save();

            $room->load('currentLot.leader');

            AuctionStateUpdated::dispatch(
                (new AuctionSnapshot($room))->build(
                    action: ['type' => 'lot_opened', 'summary' => "Se abrió el lote «{$lot->title}»"],
                )
            );

            return ActionResult::success([
                'lot_id' => $lot->id,
                'version' => $room->version,
            ]);
        });
    }

    /**
     * Registra una puja sobre el lote abierto (RF-04).
     *
     * El servidor es la única autoridad del monto: si el cliente no manda uno,
     * se usa la puja mínima; si manda uno, tiene que alcanzar esa misma cota.
     * Todas las validaciones ocurren dentro del bloqueo, así que dos pujas
     * simultáneas no pueden leer el mismo estado (RNF-02).
     *
     * No se descuenta saldo acá: al pujar se comprueba que alcance y el
     * descuento ocurre cuando el lote se cierra (RF-05, Hito 4).
     */
    public function placeBid(Participant $participant, ?int $amountCents = null): ActionResult
    {
        return DB::transaction(function () use ($participant, $amountCents): ActionResult {
            $room = $this->lockRoom();

            // Cierre perezoso: la puja que llega tarde no se acepta, pero sí
            // liquida el lote para que todos vean el resultado.
            $this->closeLotIfDue($room);

            $lot = $this->lockCurrentLot($room);

            if (! $lot instanceof Lot) {
                return ActionResult::rejected('no_open_lot', 'No hay ningún lote abierto.');
            }

            if (! $lot->isOpen()) {
                // Si venció, para el pujador el motivo es el tiempo; si lo cerró
                // el subastador, es que el lote ya no está abierto.
                return $lot->ends_at->isPast()
                    ? ActionResult::rejected('lot_expired', 'Se terminó el tiempo del lote.')
                    : ActionResult::rejected('lot_closed', 'El lote ya está cerrado.');
            }

            if ($lot->leader_participant_id === $participant->id) {
                return ActionResult::rejected('already_leading', 'Ya vas ganando este lote.');
            }

            $minimum = $this->minimumBidCents($lot);
            $amount = $amountCents ?? $minimum;

            if ($amount < $minimum) {
                return ActionResult::rejected(
                    'bid_too_low',
                    'La puja mínima es '.Money::format($minimum).'.',
                    ['minimum_cents' => $minimum],
                );
            }

            if (! $participant->canAfford($amount)) {
                return ActionResult::rejected(
                    'insufficient_balance',
                    'No te alcanza el saldo para esa puja.',
                    ['balance_cents' => $participant->balance_cents],
                );
            }

            $bid = Bid::query()->create([
                'lot_id' => $lot->id,
                'participant_id' => $participant->id,
                'amount_cents' => $amount,
            ]);

            $lot->forceFill([
                'current_price_cents' => $amount,
                'leader_participant_id' => $participant->id,
            ])->save();

            // Anti-sniping: una puja aceptada en la ventana final corre el
            // cierre. La extensión y la puja son una sola transición (4.3).
            $extended = $this->applyAntiSniping($lot);

            $room->forceFill(['version' => $room->version + 1])->save();
            $room->load('currentLot.leader');

            $summary = "{$participant->name} pujó ".Money::format($amount);

            if ($extended) {
                $summary .= " y se extendió el cierre ({$lot->extensions}/"
                    .config('auction.anti_sniping.max_extensions').')';
            }

            AuctionStateUpdated::dispatch(
                (new AuctionSnapshot($room))->build(
                    action: [
                        'type' => 'bid_placed',
                        'summary' => $summary,
                    ],
                )
            );

            return ActionResult::success([
                'bid_id' => $bid->id,
                'amount_cents' => $amount,
                'extended' => $extended,
                'ends_at' => $lot->ends_at->toIso8601String(),
                'version' => $room->version,
            ]);
        });
    }

    /**
     * Puja mínima aceptable: iguala el precio inicial en la primera puja y le
     * suma el incremento en las siguientes (4.1).
     */
    public function minimumBidCents(Lot $lot): int
    {
        return $lot->current_price_cents === null
            ? $lot->start_price_cents
            : $lot->current_price_cents + $lot->min_increment_cents;
    }

    /**
     * Cierra el lote abierto ahora mismo, a pedido del subastador (RF-03).
     *
     * Comparte el desenlace con el cierre por vencimiento: con ganador queda
     * `sold` y se le descuenta el saldo; sin pujas, `unsold`.
     */
    public function closeCurrentLot(): ActionResult
    {
        return DB::transaction(function (): ActionResult {
            $room = $this->lockRoom();
            $lot = $this->lockCurrentLot($room);

            if (! $lot instanceof Lot || ! $lot->isOpen()) {
                return ActionResult::rejected('no_open_lot', 'No hay ningún lote abierto.');
            }

            $this->settle($room, $lot);

            $closed = $room->currentLot;

            return ActionResult::success([
                'lot_id' => $closed->id,
                'status' => $closed->status->value,
                'winner_name' => $closed->winner?->name,
                'price_cents' => $closed->current_price_cents,
                'version' => $room->version,
            ]);
        });
    }

    /**
     * Cierre perezoso desde la lectura de estado (RF-09).
     *
     * Se hace una comprobación barata sin bloquear; solo si de verdad hay un
     * lote vencido sin cerrar se abre la transacción que lo liquida. Así el
     * `GET /auction/state` no paga un `lockForUpdate` en cada petición.
     */
    public function settleIfDue(): void
    {
        $lot = $this->room()?->currentLot;

        if (! $lot instanceof Lot || ! $lot->isOpen() || ! $lot->ends_at->isPast()) {
            return;
        }

        DB::transaction(function (): void {
            $this->closeLotIfDue($this->lockRoom());
        });
    }

    /**
     * Cierra el lote de la sala si su plazo ya venció (idempotente).
     *
     * Espera la sala **ya bloqueada**: forma parte de la misma transacción que
     * la acción que lo dispara, así el cierre y esa acción son una sola
     * transición de versión.
     */
    private function closeLotIfDue(AuctionRoom $room): ?Lot
    {
        $lot = $this->lockCurrentLot($room);

        if (! $lot instanceof Lot || ! $lot->isOpen() || ! $lot->ends_at->isPast()) {
            return null;
        }

        return $this->settle($room, $lot);
    }

    /**
     * Liquida un lote abierto: decide ganador, descuenta saldo y sube la versión
     * una sola vez (RF-05). Espera la sala y el lote bloqueados.
     */
    private function settle(AuctionRoom $room, Lot $lot): Lot
    {
        $leader = $lot->leader;
        $price = $lot->current_price_cents;

        if ($leader instanceof Participant && $price !== null) {
            // El descuento ocurre acá, no al pujar (4.1): el saldo ya se validó
            // contra el monto, así que no puede quedar negativo.
            $leader->forceFill(['balance_cents' => $leader->balance_cents - $price])->save();

            $lot->forceFill([
                'status' => LotStatus::Sold,
                'winner_participant_id' => $leader->id,
                'closed_at' => now(),
            ])->save();

            $summary = "Se cerró el lote «{$lot->title}»: lo ganó {$leader->name} por ".Money::format($price);
        } else {
            $lot->forceFill([
                'status' => LotStatus::Unsold,
                'closed_at' => now(),
            ])->save();

            $summary = "Se cerró el lote «{$lot->title}» sin pujas";
        }

        $room->forceFill(['version' => $room->version + 1])->save();
        $room->load('currentLot.leader', 'currentLot.winner');

        AuctionStateUpdated::dispatch(
            (new AuctionSnapshot($room))->build(
                action: ['type' => 'lot_closed', 'summary' => $summary],
            )
        );

        return $lot;
    }

    /**
     * Anti-sniping (4.3): una puja aceptada dentro de la ventana final corre el
     * cierre, con un tope de extensiones para que la subasta no dure para
     * siempre. Los valores salen de `config/auction.php`.
     *
     * Espera el lote bloqueado. Devuelve true si extendió el cierre.
     */
    private function applyAntiSniping(Lot $lot): bool
    {
        $maxExtensions = (int) config('auction.anti_sniping.max_extensions');

        if ($lot->extensions >= $maxExtensions) {
            return false;
        }

        $windowMs = (int) config('auction.anti_sniping.window_seconds') * 1000;
        $remainingMs = $lot->ends_at->getTimestampMs() - now()->getTimestampMs();

        if ($remainingMs > $windowMs) {
            return false;
        }

        $lot->forceFill([
            'ends_at' => $lot->ends_at->copy()->addSeconds(
                (int) config('auction.anti_sniping.extension_seconds')
            ),
            'extensions' => $lot->extensions + 1,
        ])->save();

        return true;
    }

    /**
     * Lote apuntado por la sala, bloqueado para escribir.
     */
    private function lockCurrentLot(AuctionRoom $room): ?Lot
    {
        if ($room->current_lot_id === null) {
            return null;
        }

        return Lot::query()->whereKey($room->current_lot_id)->lockForUpdate()->first();
    }

    /**
     * Sala única del MVP, creada si todavía no existe.
     *
     * Se bloquea antes de leer: sin este bloqueo dos acciones podrían leer el
     * mismo estado y confirmar dos transiciones sobre la misma versión
     * (RNF-02).
     */
    public function lockRoom(): AuctionRoom
    {
        return AuctionRoom::query()->lockForUpdate()->firstOrCreate([], ['version' => 0]);
    }

    /**
     * Sala actual sin bloquear, para lecturas.
     */
    public function room(): ?AuctionRoom
    {
        return AuctionRoom::query()->first();
    }

    private function hasOpenLot(AuctionRoom $room): bool
    {
        return $room->lots()
            ->where('status', LotStatus::Open->value)
            ->lockForUpdate()
            ->exists();
    }
}
