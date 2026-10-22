<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * `auction:setup`: completa las claves de Reverb y el PIN vacíos del `.env`.
 */
class SetupAuctionCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/auction-setup-'.uniqid();
        mkdir($this->dir);
        $this->app->useEnvironmentPath($this->dir);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir.'/.env');
        @rmdir($this->dir);

        parent::tearDown();
    }

    private function env(): string
    {
        return (string) file_get_contents($this->dir.'/.env');
    }

    public function test_it_fills_the_empty_keys_and_shows_the_pin(): void
    {
        file_put_contents($this->dir.'/.env', implode("\n", [
            'APP_NAME="Subasta"',
            'REVERB_APP_ID=',
            'REVERB_APP_KEY=',
            'REVERB_APP_SECRET=""',
            'VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"',
            'AUCTION_HOST_PIN=',
            '',
        ]));

        $this->artisan('auction:setup')
            ->expectsOutputToContain('PIN del subastador')
            ->assertSuccessful();

        $env = $this->env();

        $this->assertMatchesRegularExpression('/^REVERB_APP_ID=\d{6}$/m', $env);
        $this->assertMatchesRegularExpression('/^REVERB_APP_KEY=[a-z0-9]{20}$/m', $env);
        $this->assertMatchesRegularExpression('/^REVERB_APP_SECRET=[a-z0-9]{20}$/m', $env);
        $this->assertMatchesRegularExpression('/^AUCTION_HOST_PIN=\d{6}$/m', $env);
        // Lo que referencia a otras variables queda igual.
        $this->assertStringContainsString('VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"', $env);
    }

    public function test_it_never_overwrites_a_configured_value(): void
    {
        file_put_contents($this->dir.'/.env', "REVERB_APP_ID=1\nREVERB_APP_KEY=mia\nREVERB_APP_SECRET=secreta\nAUCTION_HOST_PIN=4321\n");

        $this->artisan('auction:setup')
            ->expectsOutput('El .env ya estaba completo: no se cambió nada.')
            ->assertSuccessful();

        $this->assertSame("REVERB_APP_ID=1\nREVERB_APP_KEY=mia\nREVERB_APP_SECRET=secreta\nAUCTION_HOST_PIN=4321\n", $this->env());
    }

    public function test_it_fails_without_an_env_file(): void
    {
        $this->artisan('auction:setup')->assertFailed();
    }
}
