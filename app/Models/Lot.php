<?php

namespace App\Models;

use App\Enums\LotStatus;
use Database\Factories\LotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un lote de la sala. Como máximo uno está `open` a la vez (RF-01).
 *
 * `current_price_cents` es null hasta la primera puja: mientras tanto el
 * precio de referencia es `start_price_cents` (4.1). Todos los montos son
 * enteros en centavos (RNF-03).
 */
class Lot extends Model
{
    /** @use HasFactory<LotFactory> */
    use HasFactory;

    protected $fillable = [
        'room_id',
        'title',
        'description',
        'start_price_cents',
        'min_increment_cents',
        'duration_seconds',
        'current_price_cents',
        'leader_participant_id',
        'status',
        'started_at',
        'ends_at',
        'extensions',
        'closed_at',
        'winner_participant_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LotStatus::class,
            'start_price_cents' => 'integer',
            'min_increment_cents' => 'integer',
            'duration_seconds' => 'integer',
            'current_price_cents' => 'integer',
            'extensions' => 'integer',
            'started_at' => 'datetime',
            'ends_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

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
    public function leader(): BelongsTo
    {
        return $this->belongsTo(Participant::class, 'leader_participant_id');
    }

    /**
     * @return BelongsTo<Participant, $this>
     */
    public function winner(): BelongsTo
    {
        return $this->belongsTo(Participant::class, 'winner_participant_id');
    }

    /**
     * @return HasMany<Bid, $this>
     */
    public function bids(): HasMany
    {
        return $this->hasMany(Bid::class);
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /**
     * El plazo terminó: el lote está abierto en `[started_at, ends_at)`, así que
     * el fin está excluido y una puja que llega justo en `ends_at` ya es tarde
     * (4.2). `isPast()` dejaría pasar ese instante.
     */
    public function hasExpired(): bool
    {
        return ! now()->lessThan($this->ends_at);
    }

    /**
     * Precio de referencia para la próxima puja: el actual si ya hay pujas
     * (4.1).
     */
    public function referencePriceCents(): int
    {
        return $this->current_price_cents ?? $this->start_price_cents;
    }
}
