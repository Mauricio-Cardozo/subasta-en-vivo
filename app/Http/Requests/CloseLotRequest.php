<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cierre manual del lote por parte del subastador (RF-03).
 *
 * `action_id` es opcional (RNF-05); con él, reintentar devuelve la respuesta
 * original en lugar de volver a cerrar y descontar saldo (4.7).
 */
class CloseLotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'action_id' => ['nullable', 'string', 'max:64'],
        ];
    }
}
