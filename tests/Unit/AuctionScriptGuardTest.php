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
        ] as $uri) {
            $this->assertStringContainsString("'{$uri}'", $contents, "El cliente no conoce {$uri}.");
        }
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
