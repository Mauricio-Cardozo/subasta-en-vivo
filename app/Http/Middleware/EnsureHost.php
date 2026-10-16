<?php

namespace App\Http\Middleware;

use App\Services\SessionIdentity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige el rol de subastador.
 *
 * El rol vive en la sesión del servidor: nunca se acepta desde el body ni
 * desde JavaScript (5.5). Sin PIN válido, las acciones del subastador
 * responden 403.
 */
class EnsureHost
{
    public function __construct(private readonly SessionIdentity $identity) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->identity->isHost($request)) {
            abort(403, 'Esta acción es del subastador: iniciá sesión con el PIN.');
        }

        return $next($request);
    }
}
