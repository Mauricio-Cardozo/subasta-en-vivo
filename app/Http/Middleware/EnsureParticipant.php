<?php

namespace App\Http\Middleware;

use App\Services\SessionIdentity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige que la sesión ya tenga un participante (nombre elegido).
 *
 * Los rechazos son 403 en JSON con un motivo claro: el cliente no debe
 * confundir "no autorizado" con "acción inválida".
 */
class EnsureParticipant
{
    public function __construct(private readonly SessionIdentity $identity) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->identity->participant($request) === null) {
            abort(403, 'Necesitás unirte con un nombre antes de participar.');
        }

        return $next($request);
    }
}
