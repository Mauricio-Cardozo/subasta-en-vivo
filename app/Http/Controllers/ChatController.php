<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChatMessageRequest;
use App\Services\ChatService;
use App\Services\SessionIdentity;
use Illuminate\Http\JsonResponse;

/**
 * Chat de la sala (RF-11).
 *
 * Pueden escribir el pujador (identidad de sesión) y el subastador. El servidor
 * decide quién es y con qué nombre firma; el navegador solo manda el texto. La
 * validación del texto vive en `ChatMessageRequest`.
 */
class ChatController extends Controller
{
    public function __construct(
        private readonly ChatService $chat,
        private readonly SessionIdentity $identity,
    ) {}

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
}
