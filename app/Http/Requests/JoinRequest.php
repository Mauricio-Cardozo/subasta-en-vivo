<?php

namespace App\Http\Requests;

use App\Models\Participant;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Alta de un pujador: el nombre es dato de cliente y se valida entero acá.
 *
 * Las comparaciones (nombre reservado y unicidad) son insensibles a
 * mayúsculas, así "Ana" y "ana" no pueden convivir en la sala ni nadie puede
 * hacerse pasar por "subastador" cambiando una letra.
 */
class JoinRequest extends FormRequest
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
        $min = config('auction.participant.name.min_length');
        $max = config('auction.participant.name.max_length');

        return [
            'name' => [
                'required',
                'string',
                "min:{$min}",
                "max:{$max}",
                'regex:/^[\p{L}\p{N} _.-]+$/u',
                $this->notReservedRule(),
                $this->uniqueNameRule(),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('name');

        $this->merge([
            'name' => is_string($name) ? trim($name) : $name,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Elegí un nombre para entrar a la subasta.',
            'name.min' => 'El nombre es demasiado corto.',
            'name.max' => 'El nombre es demasiado largo.',
            'name.regex' => 'El nombre solo admite letras, números, espacios, guiones y puntos.',
        ];
    }

    private function notReservedRule(): Closure
    {
        $reserved = array_map(
            fn (string $name): string => mb_strtolower($name),
            config('auction.participant.name.reserved')
        );

        return function (string $attribute, mixed $value, Closure $fail) use ($reserved): void {
            if (is_string($value) && in_array(mb_strtolower($value), $reserved, true)) {
                $fail('Ese nombre está reservado para el subastador.');
            }
        };
    }

    private function uniqueNameRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value)) {
                return;
            }

            $taken = Participant::query()
                ->whereRaw('lower(name) = ?', [mb_strtolower($value)])
                ->exists();

            if ($taken) {
                $fail('Ese nombre ya está en uso en la sala.');
            }
        };
    }
}
