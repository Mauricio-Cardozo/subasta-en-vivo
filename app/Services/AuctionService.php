<?php

namespace App\Services;

use App\Enums\LotStatus;
use App\Events\AuctionStateUpdated;
use App\Models\AuctionRoom;
use App\Models\Lot;
use App\Support\ActionResult;
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
