<?php

namespace App\Http\Middleware;

use App\Services\SessionIdentity;
use Closure;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identidad de sesión para autorizar canales (canal de presencia).
 *
 * Laravel autoriza los canales privados y de presencia con `$request->user()`,
 * y este proyecto no tiene cuentas: la identidad vive en la sesión (5.5). Solo
 * en `/broadcasting/auth`, este middleware presenta al pujador o al subastador
 * como un usuario liviano. Sin identidad no hay usuario y la suscripción se
 * rechaza (403); el canal público `auction` sigue abierto para cualquiera.
 *
 * El `id` es un seudónimo: los miembros de un canal de presencia ven el de los
 * demás, así que nunca es el id de la base ni el de la sesión.
 */
class ResolveBroadcastIdentity
{
    public function __construct(private readonly SessionIdentity $identity) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->user($request);

        $request->setUserResolver(fn (): ?GenericUser => $user);

        return $next($request);
    }

    private function user(Request $request): ?GenericUser
    {
        $participant = $this->identity->participant($request);
        $isHost = $this->identity->isHost($request);

        if ($participant === null && ! $isHost) {
            return null;
        }

        return new GenericUser([
            'id' => substr(hash_hmac('sha256', $this->identity->actorKey($request), (string) config('app.key')), 0, 16),
            'name' => $participant?->name ?? (string) config('auction.chat.host_name'),
            'role' => $participant !== null ? 'bidder' : 'host',
        ]);
    }
}
