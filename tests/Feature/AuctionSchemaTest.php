<?php

namespace Tests\Feature;

use App\Enums\LotStatus;
use App\Models\AuctionRoom;
use App\Models\Lot;
use App\Models\Participant;
use Database\Seeders\AuctionRoomSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuctionSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_room_seeder_is_idempotent(): void
    {
        $this->seed(AuctionRoomSeeder::class);
        $this->seed(AuctionRoomSeeder::class);

        $this->assertSame(1, AuctionRoom::query()->count());
        $this->assertSame(0, AuctionRoom::query()->sole()->version);
    }

    public function test_a_room_knows_its_open_lot_and_clears_it_when_closed(): void
    {
        $room = AuctionRoom::factory()->create();
        $lot = Lot::factory()->for($room, 'room')->create();
        $room->update(['current_lot_id' => $lot->id]);

        $this->assertTrue($room->fresh()->openLot()->is($lot));

        $lot->update(['status' => LotStatus::Sold, 'closed_at' => now()]);

        $this->assertNull($room->fresh()->openLot());
    }

    public function test_lot_amounts_are_integers_and_the_first_bid_matches_the_start_price(): void
    {
        $lot = Lot::factory()->create([
            'start_price_cents' => 4_500,
            'min_increment_cents' => 100,
            'current_price_cents' => null,
        ]);

        $fresh = $lot->fresh();

        $this->assertIsInt($fresh->start_price_cents);
        $this->assertIsInt($fresh->min_increment_cents);
        $this->assertSame(LotStatus::Open, $fresh->status);
        $this->assertTrue($fresh->isOpen());
        $this->assertSame(4_500, $fresh->referencePriceCents());

        $fresh->update(['current_price_cents' => 4_600]);

        $this->assertSame(4_600, $fresh->fresh()->referencePriceCents());
    }

    public function test_a_participant_starts_with_the_configured_balance(): void
    {
        $participant = Participant::factory()->create();

        $this->assertSame(config('auction.participant.starting_balance_cents'), $participant->balance_cents);
        $this->assertTrue($participant->canAfford($participant->balance_cents));
        $this->assertFalse($participant->canAfford($participant->balance_cents + 1));
    }

    public function test_participant_names_and_sessions_are_unique_per_room(): void
    {
        $first = Participant::factory()->create(['name' => 'Ana']);

        $this->expectException(QueryException::class);

        Participant::factory()->create(['name' => 'Ana', 'session_id' => $first->session_id]);
    }
}
