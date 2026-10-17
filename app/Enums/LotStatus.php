<?php

namespace App\Enums;

/**
 * Ciclo de vida de un lote.
 *
 * Un lote nace `queued` (esperando turno en la cola) y abre cuando le toca.
 * Termina `sold`, `unsold` o `cancelled` (el subastador lo quitó de la cola).
 */
enum LotStatus: string
{
    case Queued = 'queued';
    case Open = 'open';
    case Sold = 'sold';
    case Unsold = 'unsold';
    case Cancelled = 'cancelled';

    public function isQueued(): bool
    {
        return $this === self::Queued;
    }

    public function isOpen(): bool
    {
        return $this === self::Open;
    }

    /** ¿Terminó su ciclo? Un lote en cola todavía no. */
    public function isClosed(): bool
    {
        return match ($this) {
            self::Sold, self::Unsold, self::Cancelled => true,
            self::Queued, self::Open => false,
        };
    }
}
