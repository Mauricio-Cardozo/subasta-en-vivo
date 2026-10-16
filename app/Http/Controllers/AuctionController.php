<?php

namespace App\Http\Controllers;

use App\Http\Requests\OpenLotRequest;
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
    public function index(): View
    {
        return view('auction.index');
    }

    /**
     * Pantalla del subastador (emisor).
     */
    public function emisor(): View
    {
        return view('auction.emisor');
    }

    /**
     * Pantalla del pujador (receptor).
     */
    public function receptor(): View
    {
        return view('auction.receptor');
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
