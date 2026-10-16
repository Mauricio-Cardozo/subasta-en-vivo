<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Guardas de seguridad del frontend (RNF-07).
 *
 * El texto de usuario nunca debe interpretarse como HTML: si alguien agrega
 * `innerHTML` al cliente, esta prueba falla antes que el XSS llegue a main.
 */
class AuctionScriptGuardTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function scripts(): array
    {
        return glob(resource_path('js/*.js')) ?: [];
    }

    /**
     * Quita comentarios para no confundir menciones en la documentación con
     * código real (por ejemplo, un docblock que explique la regla).
     */
    private function code(string $contents): string
    {
        $stripped = preg_replace('~/\*.*?\*/~s', '', $contents) ?? $contents;

        return preg_replace('~^\s*//.*$~m', '', $stripped) ?? $stripped;
    }

    public function test_the_scripts_never_write_user_text_with_innerhtml(): void
    {
        $this->assertNotEmpty($this->scripts(), 'Se esperaba al menos un script en resources/js.');

        foreach ($this->scripts() as $script) {
            $code = $this->code(file_get_contents($script));

            foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write'] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $code,
                    basename($script)." no debe usar {$forbidden}: el texto se pinta con textContent."
                );
            }
        }
    }

    public function test_the_auction_script_listens_to_the_public_channel_once(): void
    {
        $contents = file_get_contents(resource_path('js/auction.js'));

        $this->assertStringContainsString("import './echo'", $contents);
        $this->assertSame(1, substr_count($contents, ".channel('auction')"));
        $this->assertSame(1, substr_count($contents, ".listen('.AuctionStateUpdated'"));
    }

    /**
     * El liderazgo del lote se deduce del estado público del lote, no del
     * bloque `me`: el broadcast no lleva `me`, así que al abrirse un lote nuevo
     * ese booleano quedaría pegado al lote anterior (bug real visto al probar
     * dos navegadores a mano).
     */
    public function test_leadership_is_read_from_the_public_lot(): void
    {
        $code = $this->code(file_get_contents(resource_path('js/auction.js')));

        $this->assertStringContainsString(
            'const leading = isLeader(lot)',
            $code,
            'El botón de puja debe decidir con el lote actual, no con `me.is_leader`.'
        );
        $this->assertStringContainsString('lot.leader_name === name', $code);
    }

    /**
     * Las URLs del cliente son un contrato con las rutas del servidor: si una
     * se renombra, el cliente fallaría en silencio.
     */
    public function test_the_client_urls_match_the_server_routes(): void
    {
        $contents = file_get_contents(resource_path('js/auction.js'));

        foreach ([
            route('auction.state', absolute: false),
            route('auction.join', absolute: false),
            route('host.login', absolute: false),
            route('auction.lots.store', absolute: false),
            route('auction.bids.store', absolute: false),
        ] as $uri) {
            $this->assertStringContainsString("'{$uri}'", $contents, "El cliente no conoce {$uri}.");
        }
    }

    /**
     * El cliente no manda montos: la puja efectiva la calcula el servidor con
     * la información que tiene en la transacción. Solo viaja un `action_id`
     * nuevo por intento, que es lo que hace la acción idempotente.
     */
    public function test_the_bid_request_carries_only_an_action_id(): void
    {
        $code = $this->code(file_get_contents(resource_path('js/auction.js')));

        $this->assertStringContainsString(
            'postJson(endpoints.bid, { action_id: newActionId() })',
            $code,
            'La puja debe ir solo con un action_id: el monto lo decide el servidor.'
        );
    }

    /**
     * El estado se pide por HTTP al cargar: Reverb no reemplaza al endpoint
     * (regla de oro del proyecto).
     */
    public function test_the_client_loads_the_state_over_http(): void
    {
        $contents = $this->code(file_get_contents(resource_path('js/auction.js')));

        $this->assertStringContainsString('loadState()', $contents);
        $this->assertStringContainsString('fetch(endpoints.state', $contents);
    }
}
