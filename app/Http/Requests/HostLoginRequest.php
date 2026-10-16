<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HostLoginRequest extends FormRequest
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
            'pin' => ['required', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pin.required' => 'Ingresá el PIN del subastador.',
        ];
    }
}
