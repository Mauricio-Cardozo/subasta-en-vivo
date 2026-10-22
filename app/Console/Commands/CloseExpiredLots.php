<?php

namespace App\Console\Commands;

use App\Services\AuctionService;
use Illuminate\Console\Command;

/**
 * Red de seguridad del cierre perezoso (4.4, punto 3).
 *
 * El cierre se resuelve cuando alguien interactúa (una acción o
 * `GET /auction/state`). Si nadie vuelve a mirar la sala, este comando,
 * programado cada minuto, liquida el lote vencido y abre el siguiente de la
 * cola. Usa el mismo camino bajo bloqueo, así que correrlo a la vez que una
 * petición no cierra dos veces.
 */
class CloseExpiredLots extends Command
{
    protected $signature = 'auction:close-expired';

    protected $description = 'Liquida el lote vencido y avanza la cola si nadie consultó la sala';

    public function handle(AuctionService $auction): int
    {
        $before = $auction->room()?->version;

        $auction->settleIfDue();

        $after = $auction->room()?->version;

        $this->info($before === $after
            ? 'Nada para cerrar.'
            : "Sala actualizada: versión {$before} → {$after}.");

        return self::SUCCESS;
    }
}
