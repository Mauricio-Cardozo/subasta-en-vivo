<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChatMessageRequest;
use App\Models\ChatMessage;
use App\Services\ChatService;
use App\Services\SessionIdentity;
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
    ) {}

    /**
     * Historial de la sala (RF-12).
     *
     * Fuente de verdad al cargar o reconectar: Reverb no repite lo que pasó sin
     * conexión. `no-store` evita mostrar un historial viejo.
     */
    public function index(): JsonResponse
    {
        return response()
            ->json(['messages' => $this->chat->history()])
            ->header('Cache-Control', 'no-store');
    }

    public function store(ChatMessageRequest $request): JsonResponse
    {
        $participant = $this->identity->participant($request);

        if ($participant === null && ! $this->identity->isHost($request)) {
            return response()->json([
                'reason' => 'unauthenticated',
                'message' => 'Necesitás un nombre o ser el subastador para escribir en el chat.',
            ], 403);
        }

        $message = $this->chat->post(
            $participant,
            $participant?->name ?? (string) config('auction.chat.host_name'),
            (string) $request->validated('body'),
        );

        return response()->json($this->chat->payload($message), 201);
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
            return response()->json([
                'reason' => 'forbidden',
                'message' => 'Solo el autor o el subastador pueden borrar este mensaje.',
            ], 403);
        }

        $this->chat->delete($message);

        return response()->noContent();
    }
}
