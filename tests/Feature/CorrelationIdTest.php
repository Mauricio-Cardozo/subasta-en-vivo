<?php

namespace Tests\Feature;

use App\Support\CorrelationId;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Trazabilidad de punta a punta (correlation_id).
 *
 * El id viaja de la petición a los logs y vuelve en la respuesta: es la llave
 * para encontrar en los logs lo que reporta el navegador.
 */
class CorrelationIdTest extends TestCase
{
    public function test_every_response_carries_a_correlation_id(): void
    {
        $response = $this->get('/');

        $response->assertOk();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            (string) $response->headers->get('X-Correlation-ID'),
        );
    }

    public function test_a_safe_incoming_id_is_honoured(): void
    {
        $response = $this->withHeader('X-Correlation-ID', 'prueba-123')->get('/');

        $this->assertSame('prueba-123', $response->headers->get('X-Correlation-ID'));
        $this->assertSame('prueba-123', app(CorrelationId::class)->current());
    }

    public function test_an_unsafe_incoming_id_is_replaced(): void
    {
        $response = $this->withHeader('X-Correlation-ID', 'no valido;drop table')->get('/');

        $id = (string) $response->headers->get('X-Correlation-ID');

        $this->assertNotSame('no valido;drop table', $id);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $id);
    }

    public function test_the_id_is_shared_with_the_log_context(): void
    {
        $response = $this->withHeader('X-Correlation-ID', 'cid-xyz')->get('/');

        $this->assertSame('cid-xyz', $response->headers->get('X-Correlation-ID'));
        $this->assertSame('cid-xyz', Log::sharedContext()['correlation_id'] ?? null);
    }
}
