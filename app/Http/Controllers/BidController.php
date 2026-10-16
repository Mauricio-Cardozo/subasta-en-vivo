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
 * envuelve la acción con el guard de idempotencia (4.7).
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

        return $this->idempotency->run(
            $this->identity->actorKey($request),
            $request->string('action_id')->toString(),
            fn (): ActionResult => $this->auction->placeBid($participant, $request->amountCents()),
            event: 'auction.bid',
        );
    }
}
