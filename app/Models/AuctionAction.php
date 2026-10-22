<?php

namespace App\Models;

use App\Support\RequestContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una acción de la sala, aceptada o rechazada (RF-15, 5.3).
 *
 * Es la auditoría persistente: quién (`participant_id`, `actor_key`), desde
 * dónde (`ip`), qué (`type`, `payload`), con qué resultado (`accepted`,
 * `reason`, `result`) y en qué petición (`correlation_id`). También hace
 * idempotentes las acciones (4.7): la restricción única `(actor_key,
 * action_id)` impide repetir el efecto y la segunda vez se devuelve `result`.
 * `payload` nunca guarda tokens, el PIN ni el texto completo del chat.
 */
class AuctionAction extends Model
{
    /** Actor de las transiciones que no pide nadie (cierre por tiempo). */
    public const SYSTEM_ACTOR = 'system';

    protected $fillable = [
        'type',
        'participant_id',
        'ip',
        'actor_key',
        'action_id',
        'payload',
        'payload_hash',
        'accepted',
        'reason',
        'result',
        'correlation_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'accepted' => 'boolean',
            'result' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Participant, $this>
     */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    /**
     * Registra una transición del sistema (sin actor que la pida), con la IP y
     * el `correlation_id` de la petición que la disparó, si la hay.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function recordSystem(string $type, array $payload): self
    {
        $context = app(RequestContext::class);

        return self::query()->create([
            'type' => $type,
            'actor_key' => self::SYSTEM_ACTOR,
            'ip' => $context->ip,
            'payload' => $payload,
            'accepted' => true,
            'correlation_id' => $context->correlationId,
        ]);
    }
}
