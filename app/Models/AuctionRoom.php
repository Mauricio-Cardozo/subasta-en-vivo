<?php

namespace App\Models;

use App\Enums\LotStatus;
use Database\Factories\AuctionRoomFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La sala compartida. En el MVP existe una sola fila (RF-01).
 *
 * Es la fila que se bloquea (`lockForUpdate`) al inicio de toda acción, así
 * todas las acciones se serializan y no hay riesgo de deadlock entre ellas
 * (RNF-02). `version` es la versión del estado persistido (RNF-04).
 */
class AuctionRoom extends Model
{
    /** @use HasFactory<AuctionRoomFactory> */
    use HasFactory;

    protected $fillable = [
        'version',
        'current_lot_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Lot, $this>
     */
    public function currentLot(): BelongsTo
    {
        return $this->belongsTo(Lot::class, 'current_lot_id');
    }

    /**
     * @return HasMany<Lot, $this>
     */
    public function lots(): HasMany
    {
        return $this->hasMany(Lot::class, 'room_id');
    }

    /**
     * @return HasMany<ChatMessage, $this>
     */
    public function chatMessages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'room_id');
    }

    /**
     * Lote abierto de la sala, si existe.
     */
    public function openLot(): ?Lot
    {
        /** @var Lot|null $lot */
        $lot = $this->lots()->where('status', LotStatus::Open->value)->first();

        return $lot;
    }
}
