<?php

namespace App\Support;

/**
 * Datos de la petición en curso que los servicios necesitan sin depender del
 * `Request` (por ejemplo, la IP para la auditoría). El middleware de
 * correlación lo llena al empezar la petición.
 */
class RequestContext
{
    public ?string $correlationId = null;

    public ?string $ip = null;
}
