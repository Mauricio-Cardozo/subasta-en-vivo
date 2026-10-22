<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChatMessageRequest;
use App\Models\ChatMessage;
use App\Services\ActionIdempotency;
use App\Services\AuditLogger;
use App\Services\ChatService;
use App\Services\SessionIdentity;
use App\Support\ActionResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chat de la sala (RF-11).
 *
 * Publican el pujador (identidad de sesión) y el subastador; el historial es
 * público, como el estado de la subasta. El servidor decide quién es, con qué
 * nombre firma y quién puede borrar; el navegador solo manda el texto.
 */
class ChatController extends Controller
{
    public function __construct(
        private readonly ChatService $chat,
        private readonly SessionIdentity $identity,
        private readonly AuditLogger $audit,
        private readonly ActionIdempotency $actions,
    ) {}

    /**
     * Historial de la sala (RF-12).
     *
     * Fuente de verdad al cargar o reconectar: Reverb no repite lo que pasó sin
     * conexión. Con `?after_id=` devuelve solo los mensajes posteriores (6).
     * `no-store` evita mostrar un historial viejo.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'after_id' => ['nullable', 'integer', 'min:0'],
        ]);

        $afterId = isset($validated['after_id']) ? (int) $validated['after_id'] : null;

        return response()
            ->json(['messages' => $this->chat->history($afterId)])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Publica un mensaje (RF-11).
     *
     * Pasa por el registro de acciones: queda auditado (RF-15) y, con
     * `action_id`, un reintento de red no duplica el mensaje (6). La auditoría
     * guarda el largo, no el texto.
     */
    public function store(ChatMessageRequest $request): JsonResponse
    {
        $participant = $this->identity->participant($request);
        $actorKey = $this->identity->actorKey($request);

        if ($participant === null && ! $this->identity->isHost($request)) {
            $this->actions->record('chat', $actorKey, false, 'unauthenticated');

            $this->audit->record('chat.post_denied', [
                'actor_key' => $actorKey,
                'outcome' => 'rejected',
                'reason' => 'unauthenticated',
            ]);

            return response()->json([
                'reason' => 'unauthenticated',
                'message' => 'Necesitás un nombre o ser el subastador para escribir en el chat.',
            ], 403);
        }

        $body = (string) $request->validated('body');

        return $this->actions->run(
            $actorKey,
            $request->validated('action_id'),
            function () use ($participant, $body): ActionResult {
                $message = $this->chat->post(
                    $participant,
                    $participant?->name ?? (string) config('auction.chat.host_name'),
                    $body,
                );

                return ActionResult::success($this->chat->payload($message));
            },
            event: 'chat.posted',
            type: 'chat',
            payload: ['body_length' => mb_strlen($body)],
            fingerprint: ['body' => $body],
            participantId: $participant?->id,
        );
    }

    /**
     * Borra un mensaje: puede el autor o el subastador (moderación).
     */
    public function destroy(Request $request, ChatMessage $message): Response
    {
        $participant = $this->identity->participant($request);

        $isHost = $this->identity->isHost($request);
        $isAuthor = $participant !== null && $message->participant_id === $participant->id;

        if (! $isHost && ! $isAuthor) {
            $this->actions->record('chat_delete', $this->identity->actorKey($request), false, 'forbidden',
                ['message_id' => $message->id], $participant?->id);

            $this->audit->record('chat.delete_denied', [
                'actor_key' => $this->identity->actorKey($request),
                'outcome' => 'rejected',
                'reason' => 'forbidden',
                'message_id' => $message->id,
            ]);

            return response()->json([
                'reason' => 'forbidden',
                'message' => 'Solo el autor o el subastador pueden borrar este mensaje.',
            ], 403);
        }

        $this->chat->delete($message);

        $this->actions->record('chat_delete', $this->identity->actorKey($request), true,
            payload: ['message_id' => $message->id], participantId: $participant?->id);

        $this->audit->record('chat.deleted', [
            'actor_key' => $this->identity->actorKey($request),
            'outcome' => 'accepted',
            'message_id' => $message->id,
        ]);

        return response()->noContent();
    }
}
