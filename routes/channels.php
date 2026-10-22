<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Broadcast;

/*
 * Canal de presencia de la sala: quién está conectado (pujadores y
 * subastador). El "usuario" lo arma `ResolveBroadcastIdentity` con la
 * identidad de la sesión; sin identidad, la suscripción se rechaza.
 *
 * Lo ven todos los miembros: un seudónimo, el nombre (público, como en el
 * chat) y el rol. Nunca el saldo, la IP ni la sesión.
 */
Broadcast::channel('auction.presence', fn (GenericUser $user): array => [
    // Echo entrega a `here`/`joining`/`leaving` solo esta info, sin el
    // `user_id`: el seudónimo va acá para reconocer a quien sale.
    'id' => $user->getAuthIdentifier(),
    'name' => $user->name,
    'role' => $user->role,
]);
