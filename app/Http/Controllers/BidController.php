<?php

namespace App\Http\Controllers;

use App\Http\Requests\PlaceBidRequest;
use App\Models\Participant;
use App\Services\ActionIdempotency;
use App\Services\AuctionService;
use App\Services\SessionIdentity;
use App\Support\ActionResult;
use Illuminate\Http\JsonResponse;

/**
 * Puja de un participante con identidad (RF-04).
 *
 * El efecto lo aplica `AuctionService`; acá solo se resuelve la identidad y se
 * envuelve la acción con el registro de acciones: auditoría (RF-15) e
 * idempotencia por `action_id` (4.7).
 */
class BidController extends Controller
{
    public function __construct(
        private readonly AuctionService $auction,
        private readonly SessionIdentity $identity,
        private readonly ActionIdempotency $idempotency,
    ) {}

    public function store(PlaceBidRequest $request): JsonResponse
    {
        /** @var Participant $participant */
        $participant = $this->identity->participant($request);

        $amount = $request->amountCents();

        return $this->idempotency->run(
            $this->identity->actorKey($request),
            $request->validated('action_id'),
            fn (): ActionResult => $this->auction->placeBid($participant, $amount),
            event: 'auction.bid',
            type: 'bid',
            payload: ['amount_cents' => $amount],
            participantId: $participant->id,
        );
    }
}
