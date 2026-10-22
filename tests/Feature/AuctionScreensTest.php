<?php

namespace Tests\Feature;

use App\Models\AuctionRoom;
use App\Models\Lot;
use App\Models\Participant;
use Database\Seeders\AuctionRoomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuctionScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Los tests no dependen de `npm run build`: sin Vite no hay manifest.
        $this->withoutVite();
        $this->seed(AuctionRoomSeeder::class);
        config()->set('auction.host_pin', 'pin-secreto');
    }

    private function asHost(): void
    {
        $this->postJson('/host/login', ['pin' => 'pin-secreto'])->assertOk();
    }

    private function asParticipant(string $name = 'Ana'): void
    {
        $this->postJson('/join', ['name' => $name])->assertCreated();
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

    public function test_the_host_sees_the_open_lot_form_and_no_pin_form(): void
    {
        $this->asHost();

        $this->get('/emisor')
            ->assertOk()
            ->assertViewIs('auction.emisor')
            ->assertSee('Abrir lote')
            ->assertSee('data-open-lot', escape: false)
            ->assertDontSee('data-host-login', escape: false)
            ->assertSee('data-lot-ends', escape: false);
    }

    public function test_without_the_role_the_host_screen_asks_for_the_pin(): void
    {
        $this->get('/emisor')
            ->assertOk()
            ->assertSee('data-host-login', escape: false)
            ->assertDontSee('data-open-lot', escape: false);
    }

    public function test_the_bidder_screen_asks_for_a_name_until_the_session_has_one(): void
    {
        $this->get('/receptor')
            ->assertOk()
            ->assertViewIs('auction.receptor')
            ->assertSee('data-join', escape: false)
            ->assertSee('data-balance', escape: false);

        $this->asParticipant();

        $this->get('/receptor')
            ->assertOk()
            ->assertDontSee('data-join', escape: false)
            ->assertSee('Ana');
    }

    public function test_the_screens_carry_the_state_hooks_the_client_uses(): void
    {
        foreach (['/emisor', '/receptor'] as $uri) {
            $response = $this->get($uri)->assertOk();

            foreach (['data-lot-panel', 'data-lot-title', 'data-lot-price', 'data-lot-leader', 'data-state-version'] as $hook) {
                $response->assertSee($hook, escape: false);
            }
        }
    }

    public function test_both_screens_carry_the_history_hooks_the_client_uses(): void
    {
        foreach (['/emisor', '/receptor'] as $uri) {
            $this->get($uri)
                ->assertOk()
                ->assertSee('data-history', escape: false)
                ->assertSee('data-history-empty', escape: false)
                ->assertSee('Historial de lotes');
        }
    }

    public function test_the_layout_shows_the_connection_state_with_a_live_region(): void
    {
        foreach (['/emisor', '/receptor'] as $uri) {
            $this->get($uri)
                ->assertOk()
                ->assertSee('data-connection-status', escape: false)
                ->assertSee('data-connection-dot', escape: false)
                ->assertSee('role="status"', escape: false)
                ->assertSee('aria-live="polite"', escape: false)
                ->assertSee('Conectando…');
        }
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

    public function test_the_lot_is_rendered_on_first_paint_when_it_is_already_open(): void
    {
        // El estado inicial llega por HTTP, así que el HTML no depende del canal
        // para mostrar un lote que ya estaba abierto.
        $room = AuctionRoom::query()->sole();
        $lot = Lot::factory()->for($room, 'room')->create(['title' => 'Lote heredado']);
        $room->update(['current_lot_id' => $lot->id, 'version' => 4]);

        $this->getJson('/auction/state')
            ->assertOk()
            ->assertJsonPath('lot.title', 'Lote heredado')
            ->assertJsonPath('version', 4);
    }

    public function test_the_development_trigger_is_hidden_outside_local(): void
    {
        foreach (['/', '/emisor', '/receptor'] as $uri) {
            $this->get($uri)->assertDontSee('data-dev-broadcast', escape: false);
        }
    }

    public function test_the_host_screen_keeps_working_for_a_joined_bidder(): void
    {
        // Un pujador puede mirar la pantalla del subastador, pero solo ve el PIN.
        $this->asParticipant();

        $this->get('/emisor')->assertOk()->assertSee('data-host-login', escape: false);

        $this->assertSame(1, Participant::query()->count());
    }

    public function test_the_bid_form_only_exists_for_a_session_with_a_name(): void
    {
        // Sin identidad no hay formulario de puja que el navegador pueda usar.
        $this->get('/receptor')
            ->assertOk()
            ->assertDontSee('data-bid-section', escape: false);

        $this->asParticipant();

        $this->get('/receptor')
            ->assertOk()
            ->assertSee('data-bid-section', escape: false)
            ->assertSee('data-bid class', escape: false)
            ->assertSee('data-bid-minimum', escape: false)
            // El pujador propone su monto (RF-03).
            ->assertSee('data-bid-amount', escape: false);
    }

    public function test_the_bidder_screen_lists_recent_bids(): void
    {
        $this->asParticipant();

        $this->get('/receptor')
            ->assertOk()
            ->assertSee('data-bids', escape: false)
            ->assertSee('data-bids-empty', escape: false)
            ->assertSee('Pujas recientes');
    }

    public function test_the_host_screen_has_no_bid_form(): void
    {
        $this->asHost();

        $this->get('/emisor')
            ->assertOk()
            ->assertDontSee('data-bid-section', escape: false)
            ->assertDontSee('data-bid class', escape: false);
    }

    public function test_the_host_can_close_the_open_lot_from_the_screen(): void
    {
        $this->asHost();

        $this->get('/emisor')
            ->assertOk()
            ->assertSee('data-close-section', escape: false)
            ->assertSee('data-close-lot', escape: false)
            ->assertSee('Cerrar lote');
    }

    public function test_the_bidder_has_no_close_button(): void
    {
        $this->asParticipant();

        $this->get('/receptor')
            ->assertOk()
            ->assertDontSee('data-close-lot', escape: false)
            ->assertDontSee('data-close-section', escape: false);
    }

    public function test_both_screens_show_the_countdown_and_the_winner_row(): void
    {
        foreach (['/emisor', '/receptor'] as $uri) {
            $this->get($uri)
                ->assertOk()
                ->assertSee('data-lot-countdown', escape: false)
                ->assertSee('data-lot-winner-row', escape: false)
                ->assertSee('data-lot-winner', escape: false);
        }
    }

    public function test_both_screens_carry_the_chat_hooks(): void
    {
        // Sin identidad el historial se ve, pero no el formulario: publicar
        // requiere un nombre o el rol de subastador.
        foreach (['/emisor', '/receptor'] as $uri) {
            $this->get($uri)
                ->assertOk()
                ->assertSee('data-chat', escape: false)
                ->assertSee('data-chat-messages', escape: false)
                ->assertSee('data-chat-empty', escape: false)
                ->assertDontSee('data-chat-form', escape: false)
                ->assertDontSee('data-chat-field', escape: false);
        }
    }

    public function test_the_host_can_moderate_the_chat(): void
    {
        $this->asHost();

        $this->get('/emisor')
            ->assertOk()
            ->assertSee('data-moderate="1"', escape: false)
            ->assertSee('Podés borrar cualquier mensaje');
    }

    public function test_a_bidder_chats_without_moderation(): void
    {
        $this->asParticipant();

        $this->get('/receptor')
            ->assertOk()
            ->assertSee('data-chat-form', escape: false)
            ->assertSee('data-chat-field', escape: false)
            ->assertDontSee('data-moderate', escape: false);
    }

    public function test_a_guest_reads_the_chat_but_has_no_form(): void
    {
        // El historial es público; publicar requiere identidad, así que sin
        // nombre no hay formulario que el navegador pueda usar.
        $this->get('/receptor')
            ->assertOk()
            ->assertSee('data-chat-messages', escape: false)
            ->assertDontSee('data-chat-form', escape: false)
            ->assertDontSee('data-chat-field', escape: false);
    }
}
