<?php

namespace App\Support;

/**
 * Formato de montos (RNF-03).
 *
 * El sistema entero trabaja en centavos; pasar a texto ocurre en un único
 * lugar y **sin floats**, con división entera y resto.
 */
final class Money
{
    public static function format(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return sprintf(
            '%s$ %s,%02d',
            $sign,
            number_format(intdiv($absolute, 100), 0, ',', '.'),
            $absolute % 100,
        );
    }
}
