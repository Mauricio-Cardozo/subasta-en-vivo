<?php

namespace App\Enums;

/**
 * Ciclo de vida de un lote.
 */
enum LotStatus: string
{
    case Open = 'open';
    case Sold = 'sold';
    case Unsold = 'unsold';
    case Cancelled = 'cancelled';

    public function isOpen(): bool
    {
        return $this === self::Open;
    }

    public function isClosed(): bool
    {
        return $this !== self::Open;
    }
}
