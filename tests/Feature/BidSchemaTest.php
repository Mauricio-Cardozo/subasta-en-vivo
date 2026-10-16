<?php

namespace Tests\Feature;

use App\Models\AuctionAction;
use App\Models\Bid;
use App\Models\Lot;
use App\Models\Participant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Esquema de pujas e idempotencia (RF-04, 4.7).
 */
class BidSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_bid_belongs_to_a_lot_and_a_participant(): void
    {
        $lot = Lot::factory()->create();
        $participant = Participant::factory()->create();

        $bid = Bid::factory()->create([
            'lot_id' => $lot->id,
            'participant_id' => $participant->id,
            'amount_cents' => 4_500,
        ]);

        $this->assertTrue($bid->lot->is($lot));
        $this->assertTrue($bid->participant->is($participant));
        $this->assertIsInt($bid->amount_cents);
        $this->assertSame(4_500, $bid->amount_cents);
    }

    public function test_a_lot_and_a_participant_expose_their_bids(): void
    {
        $lot = Lot::factory()->create();
        $participant = Participant::factory()->create();

        Bid::factory()->count(2)->create(['lot_id' => $lot->id, 'participant_id' => $participant->id]);

        $this->assertSame(2, $lot->bids()->count());
        $this->assertSame(2, $participant->bids()->count());
    }

    public function test_the_same_action_cannot_be_recorded_twice(): void
    {
        AuctionAction::query()->create([
            'actor_key' => 'hash-de-sesion',
            'action_id' => 'abc-123',
            'result' => ['ok' => true],
        ]);

        $this->expectException(QueryException::class);

        AuctionAction::query()->create([
            'actor_key' => 'hash-de-sesion',
            'action_id' => 'abc-123',
            'result' => ['ok' => true],
        ]);
    }

    public function test_the_same_action_id_is_allowed_for_a_different_actor(): void
    {
        AuctionAction::query()->create(['actor_key' => 'ana', 'action_id' => 'abc-123']);
        AuctionAction::query()->create(['actor_key' => 'beto', 'action_id' => 'abc-123']);

        $this->assertSame(2, AuctionAction::query()->count());
    }

    public function test_the_stored_result_round_trips_as_an_array(): void
    {
        $action = AuctionAction::query()->create([
            'actor_key' => 'hash',
            'action_id' => 'abc-123',
            'result' => ['bid_id' => 7, 'version' => 3],
        ]);

        $this->assertSame(['bid_id' => 7, 'version' => 3], $action->fresh()->result);
    }
}
