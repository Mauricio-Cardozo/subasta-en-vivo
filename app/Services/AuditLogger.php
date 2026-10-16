<?php

namespace App\Services;

use App\Support\RequestContext;
use Illuminate\Support\Facades\Log;

/**
 * Auditoría de acciones (trazabilidad).
 *
 * Cada acción que cambia estado —o que se intenta— deja un registro
 * estructurado en el canal de auditoría: quién, qué, con qué `action_id` y con
 * qué resultado. El `correlation_id` de la petición viaja en el contexto de
 * todos los registros (lo comparte el middleware de correlación).
 */
class AuditLogger
{
    public function __construct(private readonly RequestContext $context) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function record(string $event, array $context = []): void
    {
        if ($this->context->ip !== null) {
            $context['ip'] ??= $this->context->ip;
        }

        Log::channel(config('auction.audit.channel'))->info($event, $context);
    }
}
