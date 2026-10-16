<?php

namespace App\Http\Controllers;

use App\Http\Requests\OpenLotRequest;
use App\Models\Participant;
use App\Services\AuctionService;
use App\Services\AuctionSnapshot;
use App\Services\SessionIdentity;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuctionController extends Controller
{
    public function __construct(
        private readonly AuctionService $auction,
        private readonly SessionIdentity $identity,
    ) {}

    /**
     * Selector de rol: punto de entrada a la subasta en vivo.
     */
    public function index(Request $request): View
    {
        return view('auction.index', $this->identityData($request));
    }

    /**
     * Pantalla del subastador (emisor).
     */
    public function emisor(Request $request): View
    {
        return view('auction.emisor', $this->identityData($request));
    }

    /**
     * Pantalla del pujador (receptor).
     */
    public function receptor(Request $request): View
    {
        return view('auction.receptor', $this->identityData($request));
    }

    /**
     * Identidad de la sesión para las vistas.
     *
     * El rol y el nombre se resuelven en el servidor: el HTML se arma según la
     * sesión, así que no hay pantallas que el navegador pueda desbloquear.
     *
     * @return array{viewer: Participant|null, isHost: bool}
     */
    private function identityData(Request $request): array
    {
        return [
            'viewer' => $this->identity->participant($request),
            'isHost' => $this->identity->isHost($request),
        ];
    }

    /**
     * Estado actual por HTTP (RF-09).
     *
     * Reverb no reemplaza a este endpoint: es la fuente de verdad al cargar la
     * página o al reconectar. `no-store` evita que un estado viejo quede en
     * caché y se muestre como actual.
     */
    public function state(Request $request): JsonResponse
    {
        // Cierre perezoso: si el lote ya venció, primero se liquida y el estado
        // refleja el resultado (nunca se muestra un lote abierto ya vencido).
        $this->auction->settleIfDue();

        $snapshot = new AuctionSnapshot($this->auction->room());

        return response()
            ->json($snapshot->forViewer(
                viewer: $this->identity->participant($request),
                isHost: $this->identity->isHost($request),
            ))
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Abre un lote (solo subastador).
     */
    public function openLot(OpenLotRequest $request): JsonResponse
    {
        $result = $this->auction->openLot(
            title: $request->string('title')->toString(),
            description: $request->string('description')->toString() ?: null,
            startPriceCents: (int) $request->validated('start_price_cents'),
            minIncrementCents: $request->minIncrementCents(),
            durationSeconds: $request->durationSeconds(),
        );

        if (! $result->ok) {
            return response()->json([
                'reason' => $result->reason,
                'message' => $result->message,
            ], 422);
        }

        return response()->json($result->context, 201);
    }
}
