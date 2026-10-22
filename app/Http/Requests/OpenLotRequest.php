<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Apertura de un lote por parte del subastador (RF-02).
 *
 * El incremento mínimo y la duración son opcionales: si no vienen, se usan los
 * valores del balance (config/auction.php). Los montos llegan en centavos.
 *
 * `action_id` (opcional, RNF-05) hace idempotente la apertura (Tarea 2): si
 * ya hay un lote abierto o lotes en cola, el mismo envío repetido (un doble
 * clic, por ejemplo) repite la respuesta original en lugar de sumar un
 * duplicado a la cola.
 */
class OpenLotRequest extends FormRequest
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
        $titleMax = config('auction.lot.title.max_length');
        $descriptionMax = config('auction.lot.description.max_length');

        return [
            'action_id' => ['nullable', 'string', 'max:64'],
            'title' => ['required', 'string', "max:{$titleMax}"],
            'description' => ['nullable', 'string', "max:{$descriptionMax}"],
            'start_price_cents' => ['required', 'integer', 'min:1'],
            'min_increment_cents' => ['sometimes', 'integer', 'min:1'],
            'duration_seconds' => ['sometimes', 'integer', 'min:5', 'max:600'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'El lote necesita un título.',
            'title.max' => 'El título es demasiado largo.',
            'start_price_cents.required' => 'Indicá el precio inicial.',
            'start_price_cents.min' => 'El precio inicial debe ser mayor que cero.',
            'min_increment_cents.min' => 'El incremento mínimo debe ser mayor que cero.',
            'duration_seconds.min' => 'La duración mínima es de 5 segundos.',
        ];
    }

    public function minIncrementCents(): int
    {
        return (int) ($this->validated('min_increment_cents') ?? config('auction.lot.min_increment_cents'));
    }

    public function durationSeconds(): int
    {
        return (int) ($this->validated('duration_seconds') ?? config('auction.lot.duration_seconds'));
    }
}
