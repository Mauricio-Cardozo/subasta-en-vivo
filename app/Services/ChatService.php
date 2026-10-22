<?php

namespace App\Services;

use App\Events\ChatMessageDeleted;
use App\Events\ChatMessagePosted;
use App\Models\ChatMessage;
use App\Models\Participant;
use Illuminate\Support\Facades\DB;

/**
 * Chat de la sala (RF-11).
 *
 * El chat no entra en `version`: cada mensaje es una fila ordenada por `id`
 * (RF-12). El efecto se persiste en una transacción y recién después se emite el
 * evento: `ChatMessagePosted` implementa `ShouldDispatchAfterCommit`, así que
 * sale solo si la transacción confirma. La validación del texto (no vacío y tope
 * de `chat.max_length`) vive en `ChatMessageRequest`.
 */
class ChatService
{
    public function __construct(private readonly AuctionService $auction) {}

    /**
     * Publica un mensaje a nombre de un pujador o del subastador.
     *
     * El subastador no tiene fila de participante: se identifica con
     * `$authorName` (su firma), mientras que el pujador deja su id.
     */
    public function post(?Participant $participant, string $authorName, string $body): ChatMessage
    {
        $room = $this->auction->room() ?? $this->auction->lockRoom();

        $message = DB::transaction(fn (): ChatMessage => ChatMessage::query()->create([
            'room_id' => $room->id,
            'participant_id' => $participant?->id,
            'author_name' => $authorName,
            'body' => $body,
        ]));

        ChatMessagePosted::dispatch($this->payload($message));

        return $message;
    }

    /**
     * Historial de la sala, en orden de llegada (`id` ascendente).
     *
     * Sin `$afterId` son los últimos `chat.history` mensajes: el primer llenado
     * y la recarga al reconectar (RF-12), porque Reverb no garantiza lo que
     * pasó sin conexión. Con `$afterId` son los posteriores a ese id (hasta el
     * mismo tope), para pedir solo lo que falta. Los moderados no aparecen.
     *
     * @return list<array{id: int, author_name: string, body: string, posted_at: string}>
     */
    public function history(?int $afterId = null): array
    {
        $room = $this->auction->room();

        if ($room === null) {
            return [];
        }

        $limit = (int) config('auction.chat.history');
        $query = ChatMessage::query()->where('room_id', $room->id);

        $messages = $afterId === null
            ? $query->orderByDesc('id')->limit($limit)->get()->reverse()
            : $query->where('id', '>', $afterId)->orderBy('id')->limit($limit)->get();

        return $messages
            ->map(fn (ChatMessage $message): array => $this->payload($message))
            ->values()
            ->all();
    }

    /**
     * Modera un mensaje (autor o subastador). Borrado lógico (6): la fila
     * queda con `deleted_at` para la auditoría y ya no vuelve al recargar.
     */
    public function delete(ChatMessage $message): void
    {
        $id = $message->id;

        DB::transaction(fn () => $message->delete());

        ChatMessageDeleted::dispatch($id);
    }

    /**
     * Forma pública de un mensaje: id, autor, texto y momento.
     *
     * @return array{id: int, author_name: string, body: string, posted_at: string}
     */
    public function payload(ChatMessage $message): array
    {
        return [
            'id' => $message->id,
            'author_name' => $message->author_name,
            'body' => $message->body,
            'posted_at' => $message->created_at->toIso8601String(),
        ];
    }
}
