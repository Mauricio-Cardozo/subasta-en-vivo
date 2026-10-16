<?php

namespace App\Http\Middleware;

use App\Support\CorrelationId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fija el `correlation_id` de la petición (trazabilidad).
 *
 * Toma `X-Correlation-ID` si viene con un valor seguro (o genera un UUID), lo
 * publica como contexto compartido de log —así todo registro lo lleva, en
 * cualquier canal—, lo deja en `CorrelationId` y lo devuelve en la respuesta
 * para que el cliente pueda citarlo en un reporte.
 */
class AssignCorrelationId
{
    public const HEADER = 'X-Correlation-ID';

    /** Sin espacios ni saltos de línea: el valor no debe inyectar en los logs. */
    private const PATTERN = '/^[A-Za-z0-9._-]{1,64}$/';

    public function __construct(private readonly CorrelationId $correlation) {}

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->header(self::HEADER);
        $id = is_string($incoming) && preg_match(self::PATTERN, $incoming) === 1
            ? $incoming
            : (string) Str::uuid();

        $this->correlation->set($id);
        Log::shareContext(['correlation_id' => $id]);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }
}
