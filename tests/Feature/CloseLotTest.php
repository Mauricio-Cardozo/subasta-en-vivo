<?php

namespace Tests\Feature;

use App\Enums\LotStatus;
use App\Events\AuctionStateUpdated;
use App\Models\AuctionRoom;
use App\Models\Lot;
use App\Models\Participant;
use App\Services\AuctionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Cierre del lote: vencimiento perezoso, ganador y descuento de saldo (RF-05).
 *
 * El cierre se comparte entre el vencimiento (sin cron: se resuelve dentro de
 * cada acción y de la lectura de estado) y el cierre manual del subastador.
 */
class CloseLotTest extends TestCase
{
    use RefreshDatabase;

    private AuctionService $auction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->auction = app(AuctionService::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function openLot(array $attributes = []): Lot
    {
        $room = AuctionRoom::factory()->create();
        $lot = Lot::factory()->for($room, 'room')->create($attributes);
        $room->update(['current_lot_id' => $lot->id, 'version' => 1]);

        return $lot->fresh();
    }

    private function leader(Lot $lot, Participant $participant, int $priceCents): void
    {
        $lot->update([
            'current_price_cents' => $priceCents,
            'leader_participant_id' => $participant->id,
        ]);
    }

    public function test_closing_with_a_leader_sells_the_lot_and_charges_the_winner(): void
    {
        $lot = $this->openLot(['start_price_cents' => 5_000]);
        $ana = Participant::factory()->create(['name' => 'Ana', 'balance_cents' => 100_000]);
        $this->leader($lot, $ana, 5_000);

        $result = $this->auction->closeCurrentLot();

        $this->assertTrue($result->ok);

        $fresh = $lot->fresh();
        $this->assertSame(LotStatus::Sold, $fresh->status);
        $this->assertSame($ana->id, $fresh->winner_participant_id);
        $this->assertNotNull($fresh->closed_at);
        $this->assertSame(95_000, $ana->fresh()->balance_cents);
        $this->assertSame(2, $fresh->room->version);
    }

    public function test_closing_without_bids_leaves_the_lot_unsold(): void
    {
        $lot = $this->openLot();

        $result = $this->auction->closeCurrentLot();

        $this->assertTrue($result->ok);
        $this->assertSame(LotStatus::Unsold, $lot->fresh()->status);
        $this->assertNull($lot->fresh()->winner_participant_id);
        $this->assertNotNull($lot->fresh()->closed_at);
    }

    public function test_closing_twice_does_not_charge_twice(): void
    {
        $lot = $this->openLot();
        $ana = Participant::factory()->create(['balance_cents' => 100_000]);
        $this->leader($lot, $ana, 1_000);

        $first = $this->auction->closeCurrentLot();
        $second = $this->auction->closeCurrentLot();

        $this->assertTrue($first->ok);
        $this->assertFalse($second->ok);
        $this->assertSame('no_open_lot', $second->reason);
        $this->assertSame(99_000, $ana->fresh()->balance_cents);
        $this->assertSame(2, $lot->fresh()->room->version);
    }

    public function test_closing_without_an_open_lot_is_rejected(): void
    {
        AuctionRoom::factory()->create();

        $result = $this->auction->closeCurrentLot();

        $this->assertFalse($result->ok);
        $this->assertSame('no_open_lot', $result->reason);
    }

    public function test_a_late_bid_settles_the_lot_and_is_rejected(): void
    {
        $lot = $this->openLot(['ends_at' => now()->subSecond()]);
        $ana = Participant::factory()->create(['balance_cents' => 100_000]);
        $this->leader($lot, $ana, 1_000);

        $result = $this->auction->placeBid(Participant::factory()->create());

        $this->assertFalse($result->ok);
        $this->assertSame('lot_expired', $result->reason);
        $this->assertSame(LotStatus::Sold, $lot->fresh()->status);
        $this->assertSame(2, $lot->fresh()->room->version);
        $this->assertSame(99_000, $ana->fresh()->balance_cents);
    }

    public function test_opening_a_lot_settles_the_expired_one_first(): void
    {
        $expired = $this->openLot(['ends_at' => now()->subSecond()]);
        $ana = Participant::factory()->create(['balance_cents' => 100_000]);
        $this->leader($expired, $ana, 1_000);

        $result = $this->auction->openLot('Nuevo', null, 2_000, 100, 60);

        $this->assertTrue($result->ok);
        $this->assertSame(LotStatus::Sold, $expired->fresh()->status);
        $this->assertSame(99_000, $ana->fresh()->balance_cents);
    }

    public function test_the_state_read_lazily_closes_an_expired_lot(): void
    {
        $lot = $this->openLot(['ends_at' => now()->subSecond()]);
        $ana = Participant::factory()->create(['balance_cents' => 100_000]);
        $this->leader($lot, $ana, 1_000);

        $this->getJson('/auction/state')->assertOk()->assertJsonPath('lot.status', 'sold');

        $this->assertSame(LotStatus::Sold, $lot->fresh()->status);
        $this->assertSame(99_000, $ana->fresh()->balance_cents);
    }

    public function test_the_state_read_closes_the_lot_exactly_at_its_deadline(): void
    {
        $this->freezeTime();
        $lot = $this->openLot(['ends_at' => now()->addSeconds(30)]);

        $this->travelTo($lot->ends_at);

        $this->getJson('/auction/state')->assertOk()->assertJsonPath('lot.status', 'unsold');
    }

    public function test_the_state_read_leaves_a_live_lot_open(): void
    {
        $this->openLot(['ends_at' => now()->addMinute()]);

        $this->getJson('/auction/state')->assertOk()->assertJsonPath('lot.status', 'open');
    }

    public function test_a_bid_on_a_closed_lot_is_rejected_without_touching_balances(): void
    {
        $lot = $this->openLot(['status' => LotStatus::Sold, 'closed_at' => now()]);
        $ana = Participant::factory()->create(['balance_cents' => 100_000]);
        $this->leader($lot, $ana, 1_000);

        $result = $this->auction->placeBid(Participant::factory()->create());

        $this->assertFalse($result->ok);
        $this->assertSame('lot_closed', $result->reason);
        $this->assertSame(100_000, $ana->fresh()->balance_cents);
    }

    public function test_closing_announces_the_sale(): void
    {
        $lot = $this->openLot(['title' => 'Reloj']);
        $ana = Participant::factory()->create(['name' => 'Ana']);
        $this->leader($lot, $ana, 5_000);

        Event::fake([AuctionStateUpdated::class]);

        $this->auction->closeCurrentLot();

        Event::assertDispatched(AuctionStateUpdated::class, function (AuctionStateUpdated $event): bool {
            $payload = $event->broadcastWith();

            return $payload['action']['type'] === 'lot_closed'
                && $payload['action']['summary'] === 'Se cerró el lote «Reloj»: lo ganó Ana por $ 50,00'
                && $payload['lot']['status'] === 'sold'
                && $payload['lot']['winner_name'] === 'Ana';
        });
    }

    public function test_closing_without_bids_announces_it(): void
    {
        $this->openLot(['title' => 'Reloj']);

        Event::fake([AuctionStateUpdated::class]);

        $this->auction->closeCurrentLot();

        Event::assertDispatched(AuctionStateUpdated::class, function (AuctionStateUpdated $event): bool {
            $payload = $event->broadcastWith();

            return $payload['action']['type'] === 'lot_closed'
                && $payload['action']['summary'] === 'Se cerró el lote «Reloj» sin pujas'
                && $payload['lot']['status'] === 'unsold';
        });
    }
}
