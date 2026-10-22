<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Una puja (RF-04).
 *
 * `action_id` es opcional (RNF-05): el navegador lo genera para cada intento
 * y repetirlo devuelve la respuesta original en lugar de pujar dos veces
 * (4.7). El monto es la propuesta del pujador (RF-03); si falta, el servidor
 * usa la puja mínima.
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
            'action_id' => ['nullable', 'string', 'max:64'],
            'amount_cents' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
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
