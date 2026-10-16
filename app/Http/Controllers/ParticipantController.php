<?php

namespace App\Http\Controllers;

use App\Http\Requests\JoinRequest;
use App\Models\Participant;
use App\Services\AuditLogger;
use App\Services\SessionIdentity;
use Illuminate\Http\JsonResponse;

class ParticipantController extends Controller
{
    public function __construct(
        private readonly SessionIdentity $identity,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Alta de un pujador: crea el participante con el saldo inicial y lo
     * asocia a la sesión del servidor (RF-05, 5.5).
     */
    public function store(JoinRequest $request): JsonResponse
    {
        // El id de sesión se renueva primero: `session_id` guarda el hash de la
        // sesión nueva, así el participante y la sesión quedan ligados.
        $this->identity->renew($request);

        $participant = Participant::query()->create([
            'session_id' => $this->identity->hash($request),
            'name' => $request->string('name')->toString(),
            'balance_cents' => config('auction.participant.starting_balance_cents'),
        ]);

        $this->identity->attachParticipant($request, $participant);

        $this->audit->record('identity.joined', [
            'actor_key' => 'participant:'.$participant->id,
            'participant_id' => $participant->id,
            'name' => $participant->name,
        ]);

        return response()->json([
            'participant' => [
                'name' => $participant->name,
                'balance_cents' => $participant->balance_cents,
            ],
        ], 201);
    }
}
