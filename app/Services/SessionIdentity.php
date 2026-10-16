<?php

namespace App\Services;

use App\Models\Participant;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Identidad derivada de la sesión del servidor (5.5).
 *
 * El navegador nunca decide quién es: manda un nombre (dato de cliente) y el
 * servidor lo asocia a la sesión. `session_id` guarda un hash del id de sesión
 * (pseudonimizado) y es la misma clave que después se usará como `actor_key`
 * para la idempotencia de las acciones (4.7).
 */
class SessionIdentity
{
    public const PARTICIPANT_KEY = 'participant_id';

    private const HOST_KEY = 'is_host';

    /**
     * Token durable del subastador dentro de la sesión.
     *
     * No se usa el id de sesión para la `actor_key` del subastador: el id se
     * regenera (login, invalidaciones) y en las pruebas cambia en cada
     * petición, mientras que el token es un dato de la sesión y sobrevive.
     */
    private const HOST_TOKEN_KEY = 'host_token';

    /**
     * Hash estable y no reversible del id de sesión actual.
     */
    public function hash(Request $request): string
    {
        return hash('sha256', $request->session()->getId().'|'.config('app.key'));
    }

    /**
     * Clave del actor para la idempotencia de acciones (4.7).
     *
     * Es el participante, no el id de sesión: el id de sesión se regenera al
     * cambiar de identidad (y Laravel lo rota en otras situaciones), mientras
     * que el participante es la identidad durable. Así, reintentar una acción
     * desde la misma persona devuelve siempre el mismo resultado. El subastador
     * no tiene fila de participante: usa el token estable de su sesión
     * (`host:{token}`).
     */
    public function actorKey(Request $request): string
    {
        $participant = $this->participant($request);

        if ($participant !== null) {
            return 'participant:'.$participant->id;
        }

        if ($this->isHost($request)) {
            $token = $request->session()->get(self::HOST_TOKEN_KEY);

            if (is_string($token) && $token !== '') {
                return 'host:'.$token;
            }

            return 'host:'.$this->hash($request);
        }

        return 'session:'.$this->hash($request);
    }

    /**
     * Participante de la sesión actual, si ya se unió con un nombre.
     */
    public function participant(Request $request): ?Participant
    {
        $id = $request->session()->get(self::PARTICIPANT_KEY);

        if ($id === null) {
            return null;
        }

        /** @var Participant|null $participant */
        $participant = Participant::query()->find($id);

        return $participant;
    }

    public function isHost(Request $request): bool
    {
        return $request->session()->get(self::HOST_KEY) === true;
    }

    /**
     * Invalida la sesión actual y arranca una nueva, sin datos previos.
     *
     * Se llama al cambiar de identidad: el navegador nunca arrastra
     * privilegios de la identidad anterior. Debe ejecutarse **antes** de
     * calcular {@see hash()}, porque el id de sesión cambia.
     */
    public function renew(Request $request): void
    {
        $request->session()->invalidate();
    }

    /**
     * Asocia la sesión actual (ya renovada) con el participante.
     */
    public function attachParticipant(Request $request, Participant $participant): void
    {
        $request->session()->put(self::PARTICIPANT_KEY, $participant->id);
    }

    /**
     * Marca la sesión como subastador.
     *
     * El subastador es una identidad distinta: al iniciar sesión se descarta la
     * identidad de pujador que hubiera en la sesión (5.5).
     */
    public function markHost(Request $request): void
    {
        $this->renew($request);
        $request->session()->put(self::HOST_KEY, true);
        $request->session()->put(self::HOST_TOKEN_KEY, (string) Str::uuid());
    }

    public function forgetHost(Request $request): void
    {
        $request->session()->forget([self::HOST_KEY, self::HOST_TOKEN_KEY]);
    }
}
