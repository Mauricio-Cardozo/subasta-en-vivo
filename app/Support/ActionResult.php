<?php

namespace App\Support;

/**
 * Resultado de una acción del servidor (4.5).
 *
 * El servicio devuelve un resultado en lugar de lanzar excepciones para los
 * rechazos de negocio, así el controlador distingue "acción inválida" de "error
 * del servidor" y el cliente recibe un motivo concreto. Un rechazo nunca
 * modifica el estado ni emite un evento de éxito.
 */
final readonly class ActionResult
{
    /**
     * @param  array<string, mixed>  $context
     */
    private function __construct(
        public bool $ok,
        public ?string $reason = null,
        public ?string $message = null,
        public array $context = [],
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public static function success(array $context = []): self
    {
        return new self(ok: true, context: $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function rejected(string $reason, string $message, array $context = []): self
    {
        return new self(ok: false, reason: $reason, message: $message, context: $context);
    }
}
