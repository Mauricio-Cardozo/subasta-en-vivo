<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Una puja (RF-04).
 *
 * `action_id` lo genera el navegador para cada intento y hace idempotente la
 * acción: repetirlo devuelve la respuesta original en lugar de pujar dos veces
 * (4.7). El monto es opcional: si falta, el servidor usa la puja mínima.
 */
class PlaceBidRequest extends FormRequest
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
            'amount_cents' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'action_id.required' => 'Falta el identificador de la acción.',
            'amount_cents.integer' => 'La puja debe ser un monto entero en centavos.',
            'amount_cents.min' => 'La puja debe ser mayor que cero.',
        ];
    }

    public function amountCents(): ?int
    {
        $amount = $this->validated('amount_cents');

        return $amount === null ? null : (int) $amount;
    }
}
