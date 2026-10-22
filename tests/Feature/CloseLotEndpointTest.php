<?php

namespace Tests\Feature;

use App\Enums\LotStatus;
use App\Models\AuctionAction;
use App\Models\AuctionRoom;
use App\Models\Lot;
use App\Models\Participant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * POST /auction/lots/close: cierre manual del subastador (RF-03, 4.7).
 *
 * El cierre comparte el desenlace con el vencimiento (`CloseLotTest`); acá se
 * cubre la capa HTTP: autorización, idempotencia y límite de peticiones.
 */
class CloseLotEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Lot $lot;

    protected function setUp(): void
    {
        parent::setUp();

        $room = AuctionRoom::factory()->create();
        $this->lot = Lot::factory()->for($room, 'room')->create(['title' => 'Reloj']);
        $room->update(['current_lot_id' => $this->lot->id, 'version' => 1]);
    }

    private function asHost(): void
    {
        config()->set('auction.host_pin', 'pin-secreto');
        $this->postJson('/host/login', ['pin' => 'pin-secreto'])->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $actionId = 'accion-1'): array
    {
        return ['action_id' => $actionId];
    }

    public function test_a_guest_cannot_close_a_lot(): void
    {
        $this->postJson('/auction/lots/close', $this->payload())
            ->assertForbidden();

        $this->assertSame(LotStatus::Open, $this->lot->fresh()->status);
    }

    public function test_the_host_closes_the_lot_and_charges_the_winner(): void
    {
        $ana = Participant::factory()->create(['name' => 'Ana', 'balance_cents' => 100_000]);
        $this->lot->update(['current_price_cents' => 5_000, 'leader_participant_id' => $ana->id]);

        $this->asHost();

        $this->postJson('/auction/lots/close', $this->payload())
            ->assertCreated()
            ->assertJson([
                'status' => 'sold',
                'winner_name' => 'Ana',
                'price_cents' => 5_000,
                'version' => 2,
            ]);

        $this->assertSame(LotStatus::Sold, $this->lot->fresh()->status);
        $this->assertSame(95_000, $ana->fresh()->balance_cents);
    }

    public function test_closing_without_an_open_lot_is_rejected(): void
    {
        $this->lot->update(['status' => LotStatus::Sold, 'closed_at' => now()]);

        $this->asHost();

        $this->postJson('/auction/lots/close', $this->payload())
            ->assertStatus(422)
            ->assertJson(['reason' => 'no_open_lot']);

        $this->assertSame(1, $this->lot->fresh()->room->version);
    }

    public function test_action_id_is_optional(): void
    {
        $this->asHost();

        // RNF-05: sin `action_id` el cierre corre igual.
        $this->postJson('/auction/lots/close', [])->assertCreated();
    }

    public function test_the_same_action_id_does_not_close_twice(): void
    {
        $ana = Participant::factory()->create(['balance_cents' => 100_000]);
        $this->lot->update(['current_price_cents' => 5_000, 'leader_participant_id' => $ana->id]);

        $this->asHost();

        $first = $this->postJson('/auction/lots/close', $this->payload())->assertCreated();
        $second = $this->postJson('/auction/lots/close', $this->payload())->assertCreated();

        $this->assertSame($first->json(), $second->json());
        $this->assertSame(95_000, $ana->fresh()->balance_cents);
        $this->assertSame(2, $this->lot->fresh()->room->version);
        // Una acción del subastador y una sola transición de cierre.
        $this->assertSame(1, AuctionAction::query()->where('type', 'close_lot')->count());
        $this->assertSame(1, AuctionAction::query()->where('type', 'lot_closed')->count());
    }

    public function test_the_close_endpoint_is_rate_limited(): void
    {
        config()->set('auction.limits.lot_closes_per_minute', 2);
        RateLimiter::clear('lot-closes:127.0.0.1');

        $this->asHost();

        $this->postJson('/auction/lots/close', $this->payload('a'))->assertCreated();
        $this->postJson('/auction/lots/close', $this->payload('b'))->assertStatus(422);
        $this->postJson('/auction/lots/close', $this->payload('c'))->assertStatus(429);
    }
}
