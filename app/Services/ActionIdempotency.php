<?php

namespace App\Services;

use App\Models\AuctionAction;
use App\Support\ActionResult;
use App\Support\RequestContext;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Registro e idempotencia de las acciones (RF-15, 4.7).
 *
 * Toda acción deja una fila en `auction_actions`, aceptada o rechazada, con
 * su tipo, el participante, la IP y el `correlation_id`. Con `action_id` la
 * fila además "reclama" la acción: la clave `(actor_key, action_id)` es única
 * y reintentar devuelve la respuesta guardada sin volver a aplicar el efecto.
 * Reusar un `action_id` con otro contenido se rechaza (`action_id_reused`).
 * Sin `action_id` la acción se audita igual, pero no es idempotente.
 */
class ActionIdempotency
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RequestContext $context,
    ) {}

    /**
     * Ejecuta la acción una sola vez por `(actor_key, action_id)`.
     *
     * `$action` corre fuera de la transacción del servicio: los rechazos de
     * negocio también se guardan y se repiten (mismo motivo, mismo código).
     * Un error inesperado no deja la acción reclamada.
     *
     * `$payload` es lo que queda en la auditoría (sin secretos);
     * `$fingerprint`, lo que identifica el contenido para detectar un
     * `action_id` reusado (por defecto, el mismo payload). Con `$event` se
     * audita también en el canal de logs, una sola vez por camino: un
     * reintento no se cuenta como una acción nueva.
     *
     * @param  Closure(): ActionResult  $action
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $fingerprint
     */
    public function run(
        string $actorKey,
        ?string $actionId,
        Closure $action,
        ?string $event = null,
        string $type = 'action',
        array $payload = [],
        ?array $fingerprint = null,
        ?int $participantId = null,
        int $successStatus = 201,
    ): JsonResponse {
        $actionId = $actionId === '' ? null : $actionId;
        $hash = $this->hash($fingerprint ?? $payload);

        if ($actionId !== null) {
            // Camino normal de un reintento: la acción ya está registrada.
            $existing = $this->find($actorKey, $actionId);

            if ($existing instanceof AuctionAction) {
                return $this->retry($existing, $hash, $event, $type, $actorKey, $actionId, $payload, $participantId);
            }
        }

        $claim = $this->claim($type, $actorKey, $actionId, $payload, $hash, $participantId);

        if (! $claim instanceof AuctionAction) {
            // Otra petición idéntica reclamó la acción primero.
            /** @var AuctionAction $claim */
            $claim = $this->find($actorKey, (string) $actionId);

            return $this->retry($claim, $hash, $event, $type, $actorKey, (string) $actionId, $payload, $participantId);
        }

        try {
            $result = $action();
        } catch (Throwable $exception) {
            $claim->delete();

            throw $exception;
        }

        $status = $result->ok ? $successStatus : 422;
        $body = [
            'reason' => $result->reason,
            'message' => $result->message,
            ...$result->context,
        ];

        $claim->update([
            'accepted' => $result->ok,
            'reason' => $result->reason,
            'result' => ['status' => $status, 'body' => $body],
        ]);

        $this->audited($event, $actorKey, $actionId, $result->ok ? 'accepted' : 'rejected', [
            'reason' => $result->reason,
            'result' => $result->context,
        ]);

        return response()->json($body, $status);
    }

    /**
     * Deja constancia de una acción que no pasa por `run()`: una denegación
     * (403) o una acción sin respuesta que repetir, como un borrado.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $result
     */
    public function record(
        string $type,
        string $actorKey,
        bool $accepted,
        ?string $reason = null,
        array $payload = [],
        ?int $participantId = null,
        array $result = [],
    ): AuctionAction {
        return AuctionAction::query()->create([
            'type' => $type,
            'participant_id' => $participantId,
            'ip' => $this->context->ip,
            'actor_key' => $actorKey,
            'payload' => $payload,
            'accepted' => $accepted,
            'reason' => $reason,
            'result' => $result === [] ? null : $result,
            'correlation_id' => $this->context->correlationId,
        ]);
    }

    /**
     * Un `action_id` ya registrado: con el mismo contenido se repite la
     * respuesta; con otro, se rechaza sin tocar la acción original.
     *
     * @param  array<string, mixed>  $payload
     */
    private function retry(
        AuctionAction $existing,
        string $hash,
        ?string $event,
        string $type,
        string $actorKey,
        string $actionId,
        array $payload,
        ?int $participantId,
    ): JsonResponse {
        if ($existing->payload_hash !== null && ! hash_equals($existing->payload_hash, $hash)) {
            $body = [
                'reason' => 'action_id_reused',
                'message' => 'Ese identificador de acción ya se usó con otro contenido.',
            ];

            // La fila del rechazo no lleva el `action_id`: el original sigue
            // siendo el dueño de la clave.
            $this->record($type, $actorKey, false, 'action_id_reused', $payload, $participantId, [
                'status' => 422,
                'body' => $body,
            ]);

            $this->audited($event, $actorKey, $actionId, 'rejected', ['reason' => 'action_id_reused']);

            return response()->json($body, 422);
        }

        $this->audited($event, $actorKey, $actionId, is_array($existing->result) ? 'replayed' : 'in_progress');

        return $this->replay($existing);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function audited(?string $event, string $actorKey, ?string $actionId, string $outcome, array $context = []): void
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
     * Crea la fila de la acción. Con `action_id`, la restricción única decide
     * quién gana si dos peticiones idénticas llegan a la vez: el perdedor
     * recibe null y repite la respuesta del ganador.
     *
     * @param  array<string, mixed>  $payload
     */
    private function claim(
        string $type,
        string $actorKey,
        ?string $actionId,
        array $payload,
        string $hash,
        ?int $participantId,
    ): ?AuctionAction {
        try {
            return AuctionAction::query()->create([
                'type' => $type,
                'participant_id' => $participantId,
                'ip' => $this->context->ip,
                'actor_key' => $actorKey,
                'action_id' => $actionId,
                'payload' => $payload,
                'payload_hash' => $hash,
                'accepted' => null,
                'result' => null,
                'correlation_id' => $this->context->correlationId,
            ]);
        } catch (QueryException $exception) {
            if ($actionId === null || ! $this->find($actorKey, $actionId) instanceof AuctionAction) {
                throw $exception;
            }

            return null;
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
     * Huella estable del contenido: el orden de las claves no cambia el hash.
     *
     * @param  array<string, mixed>  $fingerprint
     */
    private function hash(array $fingerprint): string
    {
        return hash('sha256', json_encode($this->sorted($fingerprint), JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private function sorted(array $value): array
    {
        ksort($value);

        return array_map(fn ($item) => is_array($item) ? $this->sorted($item) : $item, $value);
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
