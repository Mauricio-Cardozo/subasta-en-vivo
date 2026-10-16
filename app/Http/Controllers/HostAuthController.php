<?php

namespace App\Http\Controllers;

use App\Http\Requests\HostLoginRequest;
use App\Services\SessionIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class HostAuthController extends Controller
{
    public function __construct(private readonly SessionIdentity $identity) {}

    /**
     * Inicia sesión como subastador comparando el PIN del entorno.
     *
     * Si el PIN no está configurado la acción siempre falla: nunca se acepta
     * un PIN vacío por una instalación sin configurar (RNF-08).
     */
    public function login(HostLoginRequest $request): JsonResponse
    {
        $expected = config('auction.host_pin');

        if (! is_string($expected) || $expected === '' || ! hash_equals($expected, $request->string('pin')->toString())) {
            throw ValidationException::withMessages([
                'pin' => 'El PIN no es correcto.',
            ]);
        }

        $this->identity->markHost($request);

        return response()->json(['host' => true]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->identity->forgetHost($request);

        return response()->json(['host' => false]);
    }
}
