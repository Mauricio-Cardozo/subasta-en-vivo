<?php

namespace App\Http\Controllers;

use App\Http\Requests\PlaceBidRequest;
use App\Models\AuctionAction;
use App\Models\Participant;
use App\Services\AuctionService;
use App\Services\SessionIdentity;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Puja de un participante con identidad (RF-04).
 *
 * La idempotencia vive acá, no en el servicio: `action_id` + el hash de la
 * sesión forman la clave única. La primera petición "reclama" la acción;
 * reintentar con el mismo `action_id` devuelve la respuesta guardada (4.7).
 */
class BidController extends Controller
{
    public function __construct(
        private readonly AuctionService $auction,
        private readonly SessionIdentity $identity,
    ) {}

    public function store(PlaceBidRequest $request): JsonResponse
    {
        /** @var Participant $participant */
        $participant = $this->identity->participant($request);
        $actorKey = $this->identity->actorKey($request);
        $actionId = $request->string('action_id')->toString();

        // Camino normal de un reintento: la acción ya está registrada.
        $existing = $this->findAction($actorKey, $actionId);

        if ($existing instanceof AuctionAction) {
            return $this->replay($existing);
        }

        $action = $this->claim($actorKey, $actionId);

        if ($action instanceof JsonResponse) {
            return $action;
        }

        try {
            $result = $this->auction->placeBid($participant, $request->amountCents());
        } catch (Throwable $exception) {
            // Un error inesperado no deja la acción reclamada: se puede reintentar.
            $action->delete();

            throw $exception;
        }

        $status = $result->ok ? 201 : 422;
        $body = [
            'reason' => $result->reason,
            'message' => $result->message,
            ...$result->context,
        ];

        $action->update(['result' => ['status' => $status, 'body' => $body]]);

        return response()->json($body, $status);
    }

    /**
     * Reclama la acción insertando la fila; la restricción única decide quién
     * gana si dos peticiones idénticas llegan a la vez.
     */
    private function claim(string $actorKey, string $actionId): AuctionAction|JsonResponse
    {
        try {
            return AuctionAction::query()->create([
                'actor_key' => $actorKey,
                'action_id' => $actionId,
                'result' => null,
            ]);
        } catch (QueryException) {
            $existing = $this->findAction($actorKey, $actionId);

            return $existing instanceof AuctionAction
                ? $this->replay($existing)
                : response()->json(['message' => 'La acción ya está en curso.'], 409);
        }
    }

    private function findAction(string $actorKey, string $actionId): ?AuctionAction
    {
        return AuctionAction::query()
            ->where('actor_key', $actorKey)
            ->where('action_id', $actionId)
            ->first();
    }

    /**
     * Repite la respuesta original. Una acción reclamada pero sin resultado
     * todavía (dos peticiones a la vez) responde 409 en lugar de duplicar.
     */
    private function replay(AuctionAction $action): JsonResponse
    {
        if (! is_array($action->result)) {
            return response()->json(['message' => 'La acción ya está en curso.'], 409);
        }

        return response()->json($action->result['body'] ?? [], $action->result['status'] ?? 200);
    }
}
