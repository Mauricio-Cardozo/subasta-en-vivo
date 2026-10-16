<?php

namespace App\Models;

use Database\Factories\ParticipantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un pujador de la sala. No hay login en el MVP: el vínculo con el navegador
 * es la sesión del servidor, guardada como hash en `session_id` (5.5).
 */
class Participant extends Model
{
    /** @use HasFactory<ParticipantFactory> */
    use HasFactory;

    protected $fillable = [
        'session_id',
        'name',
        'balance_cents',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'balance_cents' => 'integer',
        ];
    }

    /**
     * @return HasMany<Lot, $this>
     */
    public function lotsWon(): HasMany
    {
        return $this->hasMany(Lot::class, 'winner_participant_id');
    }

    /**
     * @return HasMany<Bid, $this>
     */
    public function bids(): HasMany
    {
        return $this->hasMany(Bid::class);
    }

    /**
     * ¿Le alcanza el saldo para pujar este monto? (RF-05)
     */
    public function canAfford(int $amountCents): bool
    {
        return $amountCents <= $this->balance_cents;
    }
}
