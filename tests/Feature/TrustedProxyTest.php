<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Detrás de un túnel HTTPS (cloudflared, ngrok) la petición llega desde el
 * agente local con `X-Forwarded-*`. Solo se confía en esas cabeceras si el
 * proxy está en `TRUSTED_PROXIES`.
 */
class TrustedProxyTest extends TestCase
{
    private const TUNNEL_HEADERS = [
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-For' => '203.0.113.9',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_test/request-info', fn (Request $request) => [
            'secure' => $request->isSecure(),
            'ip' => $request->ip(),
            'asset' => asset('build/app.js'),
        ]);
    }

    public function test_the_tunnel_is_trusted_when_configured(): void
    {
        config()->set('trustedproxy.proxies', '127.0.0.1');

        $this->getJson('/_test/request-info', self::TUNNEL_HEADERS)
            ->assertOk()
            ->assertJson([
                'secure' => true,
                'ip' => '203.0.113.9',
            ])
            ->assertJsonPath('asset', fn (string $url) => str_starts_with($url, 'https://'));
    }

    public function test_forwarded_headers_are_ignored_by_default(): void
    {
        config()->set('trustedproxy.proxies', null);

        $this->getJson('/_test/request-info', self::TUNNEL_HEADERS)
            ->assertOk()
            ->assertJson([
                'secure' => false,
                'ip' => '127.0.0.1',
            ]);
    }
}
