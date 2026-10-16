<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cierre manual del lote por parte del subastador (RF-03).
 *
 * `action_id` hace idempotente la acción: reintentar devuelve la respuesta
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
            'action_id' => ['required', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'action_id.required' => 'Falta el identificador de la acción.',
        ];
    }
}
