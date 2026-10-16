<?php

// Nombre con el que firma el subastador y que ningún pujador puede tomar.
$hostName = 'Subastador';

return [

    /*
    |--------------------------------------------------------------------------
    | Balance de la subasta
    |--------------------------------------------------------------------------
    |
    | Todo lo ajustable vive acá: nada de incrementos, duraciones ni saldos
    | hardcodeados en los servicios (RNF-10). Los montos son enteros en
    | centavos (RNF-03): el formato a pesos se hace solo al mostrar.
    |
    */

    // PIN del subastador. Vive en el entorno (RNF-08), nunca en el repositorio.
    'host_pin' => env('AUCTION_HOST_PIN'),

    // Moneda usada solo al mostrar montos.
    'currency' => 'ARS',

    'participant' => [
        // Saldo virtual inicial de cada pujador: 100 000 centavos = $1.000,00.
        'starting_balance_cents' => 100_000,
        'name' => [
            'min_length' => 3,
            'max_length' => 20,
            // Nombre reservado para el rol de subastador.
            'reserved' => [$hostName],
        ],
    ],

    'lot' => [
        'duration_seconds' => 60,
        'min_increment_cents' => 100,
        'title' => ['max_length' => 80],
        'description' => ['max_length' => 200],
    ],

    // Anti-sniping (4.3): una puja aceptada dentro de la ventana final extiende
    // el cierre, con un tope para impedir subastas infinitas.
    'anti_sniping' => [
        'window_seconds' => 10,
        'extension_seconds' => 10,
        'max_extensions' => 5,
    ],

    'limits' => [
        // Límites por sesión aplicados en el servidor (RNF-06).
        'bids_per_second' => 3,
        'chat_messages_per_second' => 1,
        'lot_opens_per_minute' => 10,
        'lot_closes_per_minute' => 10,
        // Altas de pujador y intentos de PIN por minuto.
        'join_per_minute' => 10,
        'host_login_per_minute' => 6,
    ],

    'chat' => [
        'max_length' => 280,
        // Mensajes recuperados al cargar o reconectar (RF-12).
        'history' => 50,
        // Firma del subastador en el chat: no tiene fila de participante.
        'host_name' => $hostName,
    ],

    'snapshot' => [
        // Pujas incluidas en el snapshot público (RF-10).
        'bids' => 10,
    ],
];
