<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Acción ya procesada, para hacerlas idempotentes (4.7).
 *
 * La restricción única `(actor_key, action_id)` es la que garantiza que un
 * reintento no repita el efecto: la segunda vez se devuelve `result`.
 */
class AuctionAction extends Model
{
    protected $fillable = [
        'actor_key',
        'action_id',
        'result',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'result' => 'array',
        ];
    }
}
