<?php

namespace App\Services;

use App\Models\AuctionAction;
use App\Support\ActionResult;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Guard de idempotencia de las acciones (4.7).
 *
 * La clave es `(actor_key, action_id)`: la primera petición "reclama" la acción
 * insertando la fila y guarda la respuesta; reintentar con el mismo
 * `action_id` devuelve esa respuesta sin volver a aplicar el efecto. Lo usan
 * por igual las pujas y las acciones del subastador.
 */
class ActionIdempotency
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Ejecuta la acción una sola vez por `(actor_key, action_id)`.
     *
     * `$action` corre fuera de la transacción del servicio: los rechazos de
     * negocio también se guardan y se repiten (mismo motivo, mismo código).
     * Un error inesperado no deja la acción reclamada.
     *
     * Con `$event` se audita el resultado (aceptada, rechazada, repetida o en
     * curso) una sola vez por camino: un reintento no se cuenta como una acción
     * nueva.
     *
     * @param  Closure(): ActionResult  $action
     */
    public function run(string $actorKey, string $actionId, Closure $action, ?string $event = null): JsonResponse
    {
        // Camino normal de un reintento: la acción ya está registrada.
        $existing = $this->find($actorKey, $actionId);

        if ($existing instanceof AuctionAction) {
            $this->audited($event, $actorKey, $actionId, is_array($existing->result) ? 'replayed' : 'in_progress');

            return $this->replay($existing);
        }

        $claim = $this->claim($actorKey, $actionId);

        if ($claim instanceof JsonResponse) {
            $this->audited($event, $actorKey, $actionId, 'in_progress');

            return $claim;
        }

        try {
            $result = $action();
        } catch (Throwable $exception) {
            $claim->delete();

            throw $exception;
        }

        $status = $result->ok ? 201 : 422;
        $body = [
            'reason' => $result->reason,
            'message' => $result->message,
            ...$result->context,
        ];

        $claim->update(['result' => ['status' => $status, 'body' => $body]]);

        $this->audited($event, $actorKey, $actionId, $result->ok ? 'accepted' : 'rejected', [
            'reason' => $result->reason,
            'result' => $result->context,
        ]);

        return response()->json($body, $status);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function audited(?string $event, string $actorKey, string $actionId, string $outcome, array $context = []): void
    {
        if ($event === null) {
            return;
        }

        $this->audit->record($event, [
            'actor_key' => $actorKey,
            'action_id' => $actionId,
            'outcome' => $outcome,
            ...$context,
        ]);
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
            $existing = $this->find($actorKey, $actionId);

            return $existing instanceof AuctionAction
                ? $this->replay($existing)
                : response()->json(['message' => 'La acción ya está en curso.'], 409);
        }
    }

    private function find(string $actorKey, string $actionId): ?AuctionAction
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
