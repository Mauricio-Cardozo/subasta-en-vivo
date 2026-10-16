<?php

namespace App\Models;

use Database\Factories\BidFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una puja confirmada. Inmutable: es el registro de lo que pasó.
 *
 * `amount_cents` es el precio que quedó en el lote en esa transición (RNF-03),
 * decidido por el servidor, no por el cliente.
 */
class Bid extends Model
{
    /** @use HasFactory<BidFactory> */
    use HasFactory;

    protected $fillable = [
        'lot_id',
        'participant_id',
        'amount_cents',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Lot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    /**
     * @return BelongsTo<Participant, $this>
     */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }
}
