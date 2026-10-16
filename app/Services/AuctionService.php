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
            $lot = $this->lockCurrentLot($room);

            if (! $lot instanceof Lot) {
                return ActionResult::rejected('no_open_lot', 'No hay ningún lote abierto.');
            }

            if (! $lot->isOpen()) {
                return ActionResult::rejected('lot_closed', 'El lote ya está cerrado.');
            }

            if ($lot->ends_at->isPast()) {
                return ActionResult::rejected('lot_expired', 'Se terminó el tiempo del lote.');
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

            $room->forceFill(['version' => $room->version + 1])->save();
            $room->load('currentLot.leader');

            AuctionStateUpdated::dispatch(
                (new AuctionSnapshot($room))->build(
                    action: [
                        'type' => 'bid_placed',
                        'summary' => "{$participant->name} pujó ".Money::format($amount),
                    ],
                )
            );

            return ActionResult::success([
                'bid_id' => $bid->id,
                'amount_cents' => $amount,
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
