<?php

namespace App\Http\Controllers\Dev;

use App\Events\AuctionStateUpdated;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Emisor de eventos de prueba para validar Reverb + Echo.
 *
 * Solo existe en entorno local (ver routes/web.php). No toca la base de datos:
 * publica un snapshot mínimo y confirmado para verificar el transporte.
 */
class BroadcastController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'version' => ['sometimes', 'integer', 'min:1'],
            'summary' => ['sometimes', 'string', 'max:80'],
        ]);

        $version = $validated['version'] ?? 1;

        AuctionStateUpdated::dispatch([
            'version' => $version,
            'lot' => null,
            'bids' => [],
            'action' => [
                'type' => 'dev_ping',
                'summary' => $validated['summary'] ?? 'Evento de prueba',
            ],
            'server_now' => now()->toIso8601String(),
            'correlation_id' => null,
        ]);

        return response()->json(['dispatched' => true, 'version' => $version]);
    }
}
