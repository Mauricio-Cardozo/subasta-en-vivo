<?php

namespace Tests\Feature;

use Tests\TestCase;

class AuctionScreensTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Los tests no dependen de `npm run build`: sin Vite no hay manifest.
        $this->withoutVite();
    }

    public function test_index_screen_lists_both_roles(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertViewIs('auction.index')
            ->assertSee('Subastador')
            ->assertSee('Pujador')
            ->assertSee(route('auction.emisor'), escape: false)
            ->assertSee(route('auction.receptor'), escape: false);
    }

    public function test_emisor_screen_renders_for_the_host_role(): void
    {
        $this->get('/emisor')
            ->assertOk()
            ->assertViewIs('auction.emisor')
            ->assertSee('Abrir lote')
            ->assertSee('data-lot-countdown', escape: false);
    }

    public function test_receptor_screen_renders_for_the_bidder_role(): void
    {
        $this->get('/receptor')
            ->assertOk()
            ->assertViewIs('auction.receptor')
            ->assertSee('Lote activo')
            ->assertSee('data-balance', escape: false);
    }

    public function test_both_screens_share_the_layout_and_navigation(): void
    {
        foreach (['/emisor', '/receptor'] as $uri) {
            $this->get($uri)
                ->assertOk()
                ->assertSee('El servidor es la única autoridad')
                ->assertSee(route('auction.emisor'), escape: false)
                ->assertSee(route('auction.receptor'), escape: false);
        }
    }

    public function test_the_development_trigger_is_hidden_outside_local(): void
    {
        foreach (['/', '/emisor', '/receptor'] as $uri) {
            $this->get($uri)->assertDontSee('data-dev-broadcast', escape: false);
        }
    }
}
