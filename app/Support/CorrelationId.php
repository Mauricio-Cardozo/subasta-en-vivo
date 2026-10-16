<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Identificador de correlación de la petición (trazabilidad).
 *
 * Un mismo valor viaja de la petición a los logs y vuelve en la respuesta: un
 * problema reportado desde el navegador (cabecera `X-Correlation-ID`) se
 * encuentra en los logs sin adivinar. Vive una petición: el middleware lo fija
 * al empezar.
 */
class CorrelationId
{
    private ?string $value = null;

    /**
     * Valor actual; si nadie lo fijó todavía (fuera del ciclo HTTP, p. ej. un
     * comando de consola), genera uno.
     */
    public function get(): string
    {
        return $this->value ??= (string) Str::uuid();
    }

    public function set(string $value): void
    {
        $this->value = $value;
    }

    public function current(): ?string
    {
        return $this->value;
    }
}
