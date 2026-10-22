<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Un mensaje de chat (RF-11).
 *
 * El texto se recorta antes de validar, así un mensaje en blanco no cuenta como
 * contenido. El tope sale de `config('auction.chat.max_length')` (RNF-10).
 */
class ChatMessageRequest extends FormRequest
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
        $max = config('auction.chat.max_length');

        return [
            'body' => ['required', 'string', "max:{$max}"],
            // Opcional (RNF-05): evita duplicar el mensaje si la red reintenta.
            'action_id' => ['nullable', 'string', 'max:64'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $body = $this->input('body');

        $this->merge([
            'body' => is_string($body) ? trim($body) : $body,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => 'Escribí algo antes de enviar.',
            'body.max' => 'El mensaje es demasiado largo.',
        ];
    }
}
