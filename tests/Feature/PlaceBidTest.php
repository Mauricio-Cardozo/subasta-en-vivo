<?php

namespace Tests\Feature;

use App\Enums\LotStatus;
use App\Events\AuctionStateUpdated;
use App\Models\AuctionRoom;
use App\Models\Bid;
use App\Models\Lot;
use App\Models\Participant;
use App\Services\AuctionService;
use App\Support\ActionResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Pujas sobre el lote abierto (RF-04, RF-05, RNF-02).
 */
class PlaceBidTest extends TestCase
{
    use RefreshDatabase;

    private AuctionService $auction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->auction = app(AuctionService::class);
    }

    /**
     * @param  array<string, mixed>  $lotAttributes
     */
    private function openLot(array $lotAttributes = []): Lot
    {
        $room = AuctionRoom::factory()->create();
        $lot = Lot::factory()->for($room, 'room')->create($lotAttributes);
        $room->update(['current_lot_id' => $lot->id]);

        return $lot->fresh();
    }

    private function bidder(int $balanceCents = 100_000, ?string $name = null): Participant
    {
        return Participant::factory()->create(array_filter([
            'balance_cents' => $balanceCents,
            'name' => $name,
        ], fn ($value) => $value !== null));
    }

    public function test_the_first_bid_matches_the_start_price_and_takes_the_lead(): void
    {
        $lot = $this->openLot(['start_price_cents' => 5_000, 'min_increment_cents' => 100]);
        $ana = $this->bidder();

        $result = $this->auction->placeBid($ana);

        $this->assertTrue($result->ok);
        $this->assertSame(5_000, $result->context['amount_cents']);

        $fresh = $lot->fresh();
        $this->assertSame(5_000, $fresh->current_price_cents);
        $this->assertSame($ana->id, $fresh->leader_participant_id);
        $this->assertSame(1, $fresh->room->version);
        $this->assertSame(1, Bid::query()->count());
    }

    public function test_each_new_bid_has_to_add_the_minimum_increment(): void
    {
        $lot = $this->openLot(['start_price_cents' => 5_000, 'min_increment_cents' => 250]);
        $ana = $this->bidder();
        $beto = $this->bidder();

        $this->auction->placeBid($ana);
        $this->auction->placeBid($beto);

        $this->assertSame(5_250, $lot->fresh()->current_price_cents);
        $this->assertSame($beto->id, $lot->fresh()->leader_participant_id);
    }

    public function test_a_client_amount_at_or_above_the_minimum_is_accepted(): void
    {
        $lot = $this->openLot(['start_price_cents' => 5_000, 'min_increment_cents' => 100]);
        $ana = $this->bidder();

        $result = $this->auction->placeBid($ana, 9_000);

        $this->assertTrue($result->ok);
        $this->assertSame(9_000, $lot->fresh()->current_price_cents);
    }

    public function test_an_amount_below_the_minimum_is_rejected_with_the_boundary(): void
    {
        $lot = $this->openLot(['start_price_cents' => 5_000, 'min_increment_cents' => 100]);
        $ana = $this->bidder();
        $this->auction->placeBid($ana);

        $beto = $this->bidder();
        $result = $this->auction->placeBid($beto, 5_000);

        $this->assertFalse($result->ok);
        $this->assertSame('bid_too_low', $result->reason);
        $this->assertSame(5_100, $result->context['minimum_cents']);
        $this->assertSame(5_000, $lot->fresh()->current_price_cents);
        $this->assertSame(1, Bid::query()->count());
    }

    public function test_a_bid_above_the_balance_is_rejected(): void
    {
        $lot = $this->openLot(['start_price_cents' => 5_000]);
        $poor = $this->bidder(balanceCents: 4_000);

        $result = $this->auction->placeBid($poor);

        $this->assertFalse($result->ok);
        $this->assertSame('insufficient_balance', $result->reason);
        $this->assertSame(4_000, $result->context['balance_cents']);
        $this->assertNull($lot->fresh()->current_price_cents);
        $this->assertSame(0, Bid::query()->count());
    }

    public function test_the_current_leader_cannot_bid_against_themselves(): void
    {
        $lot = $this->openLot();
        $ana = $this->bidder();
        $this->auction->placeBid($ana);

        $result = $this->auction->placeBid($ana);

        $this->assertFalse($result->ok);
        $this->assertSame('already_leading', $result->reason);
        $this->assertSame(1, Bid::query()->count());
        $this->assertSame(1, $lot->fresh()->room->version);
    }

    public function test_the_validation_order_follows_the_plan(): void
    {
        // 4.1: cerrado → monto bajo → ya es líder → saldo. El primer fallo
        // define el motivo aunque haya varios.
        $lot = $this->openLot(['start_price_cents' => 1_000, 'min_increment_cents' => 100]);
        $ana = $this->bidder(balanceCents: 1_050);
        $this->auction->placeBid($ana, 1_000);

        // Líder con un monto bajo: manda el monto bajo.
        $this->assertSame('bid_too_low', $this->auction->placeBid($ana, 1_050)->reason);

        // Líder con un monto válido pero sin saldo: manda que ya es líder.
        $this->assertSame('already_leading', $this->auction->placeBid($ana, 5_000)->reason);

        // Otro pujador con monto válido sin saldo: manda el saldo.
        $beto = $this->bidder(balanceCents: 1_000);
        $this->assertSame('insufficient_balance', $this->auction->placeBid($beto, 1_100)->reason);

        $this->assertSame(1, $lot->bids()->count());
    }

    public function test_bidding_without_an_open_lot_is_rejected(): void
    {
        AuctionRoom::factory()->create();

        $result = $this->auction->placeBid($this->bidder());

        $this->assertFalse($result->ok);
        $this->assertSame('no_open_lot', $result->reason);
    }

    public function test_bidding_on_a_closed_lot_is_rejected(): void
    {
        $lot = $this->openLot();
        $lot->update(['status' => LotStatus::Sold, 'closed_at' => now()]);

        $result = $this->auction->placeBid($this->bidder());

        $this->assertFalse($result->ok);
        $this->assertSame('lot_closed', $result->reason);
    }

    public function test_bidding_after_the_deadline_is_rejected(): void
    {
        $this->openLot(['ends_at' => now()->subSecond()]);

        $result = $this->auction->placeBid($this->bidder());

        $this->assertFalse($result->ok);
        $this->assertSame('lot_expired', $result->reason);
    }

    public function test_a_bid_one_millisecond_before_the_deadline_is_accepted(): void
    {
        $this->freezeTime();
        $lot = $this->openLot(['ends_at' => now()->addSeconds(30)]);

        $this->travelTo($lot->ends_at->copy()->subMillisecond());
        $result = $this->auction->placeBid($this->bidder());

        $this->assertTrue($result->ok);
    }

    public function test_a_bid_exactly_at_the_deadline_is_rejected_and_settles_the_lot(): void
    {
        $this->freezeTime();
        $lot = $this->openLot(['ends_at' => now()->addSeconds(30)]);

        // El fin está excluido (4.2): ni se acepta ni extiende el cierre.
        $this->travelTo($lot->ends_at);
        $result = $this->auction->placeBid($this->bidder());

        $this->assertFalse($result->ok);
        $this->assertSame('lot_expired', $result->reason);
        $this->assertSame(0, $lot->fresh()->extensions);
        $this->assertSame(LotStatus::Unsold, $lot->fresh()->status);
    }

    public function test_every_accepted_bid_bumps_the_version_exactly_once(): void
    {
        $lot = $this->openLot(['start_price_cents' => 1_000, 'min_increment_cents' => 100]);
        $ana = $this->bidder();
        $beto = $this->bidder();

        $this->auction->placeBid($ana);
        $this->auction->placeBid($beto);
        $this->auction->placeBid($ana);

        $this->assertSame(3, $lot->fresh()->room->version);
        $this->assertSame(3, Bid::query()->count());
        $this->assertSame(1_200, $lot->fresh()->current_price_cents);
    }

    public function test_a_rejected_bid_changes_nothing_and_emits_no_event(): void
    {
        $lot = $this->openLot(['start_price_cents' => 5_000, 'min_increment_cents' => 100]);
        $ana = $this->bidder();
        $this->auction->placeBid($ana);

        Event::fake([AuctionStateUpdated::class]);

        $beto = $this->bidder();
        $rejected = $this->auction->placeBid($beto, 10);

        $this->assertFalse($rejected->ok);
        Event::assertNotDispatched(AuctionStateUpdated::class);
        $this->assertSame(1, Bid::query()->count());
        $this->assertSame(1, $lot->fresh()->room->version);
        $this->assertSame($ana->id, $lot->fresh()->leader_participant_id);
    }

    public function test_the_event_carries_the_new_leader_and_price(): void
    {
        $lot = $this->openLot(['start_price_cents' => 5_000, 'min_increment_cents' => 100, 'title' => 'Reloj']);
        $ana = $this->bidder(name: 'Ana');

        Event::fake([AuctionStateUpdated::class]);

        $this->auction->placeBid($ana, 7_500);

        Event::assertDispatched(AuctionStateUpdated::class, function (AuctionStateUpdated $event): bool {
            $payload = $event->broadcastWith();

            return $payload['version'] === 1
                && $payload['lot']['current_price_cents'] === 7_500
                && $payload['lot']['reference_price_cents'] === 7_500
                && $payload['lot']['leader_name'] === 'Ana'
                && $payload['action']['type'] === 'bid_placed'
                && $payload['action']['summary'] === 'Ana pujó $ 75,00'
                && ! array_key_exists('leader_participant_id', $payload['lot']);
        });
    }

    public function test_the_bid_records_the_effective_amount(): void
    {
        $lot = $this->openLot(['start_price_cents' => 5_000]);
        $ana = $this->bidder(name: 'Ana');

        $this->auction->placeBid($ana);

        $bid = Bid::query()->sole();
        $this->assertSame($lot->id, $bid->lot_id);
        $this->assertSame($ana->id, $bid->participant_id);
        $this->assertSame(5_000, $bid->amount_cents);
    }

    public function test_the_result_is_a_success_action_result(): void
    {
        $this->openLot();

        $this->assertInstanceOf(ActionResult::class, $this->auction->placeBid($this->bidder()));
    }
}
