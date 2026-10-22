<?php

namespace App\Models;

use Database\Factories\ChatMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un mensaje del chat de la sala (RF-11).
 *
 * `author_name` es la copia del nombre al publicar: el subastador no tiene
 * participante y un autor borrado no debe dejar el mensaje sin nombre. El chat
 * no usa `version`: se ordena por `id` (RF-12). Un mensaje moderado no se
 * elimina: queda con `deleted_at` (borrado lógico).
 */
class ChatMessage extends Model
{
    /** @use HasFactory<ChatMessageFactory> */
    use HasFactory;

    // La moderación marca `deleted_at` (6): la fila queda para la auditoría y
    // las consultas normales ya no la ven.
    use SoftDeletes;

    protected $fillable = [
        'room_id',
        'participant_id',
        'author_name',
        'body',
    ];

    /**
     * @return BelongsTo<AuctionRoom, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(AuctionRoom::class, 'room_id');
    }

    /**
     * @return BelongsTo<Participant, $this>
     */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }
}
