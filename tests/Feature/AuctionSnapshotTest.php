<?php

namespace Tests\Feature;

use App\Enums\LotStatus;
use App\Models\AuctionRoom;
use App\Models\Lot;
use App\Models\Participant;
use App\Services\AuctionSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RNF-07: el snapshot público no filtra datos internos.
 */
class AuctionSnapshotTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const INTERNAL_KEYS = [
        'leader_participant_id',
        'winner_participant_id',
        'room_id',
        'session_id',
        'balance_cents',
    ];

    public function test_an_empty_room_reports_version_zero_and_no_lot(): void
    {
        $state = (new AuctionSnapshot(null))->build();

        $this->assertSame(0, $state['version']);
        $this->assertNull($state['lot']);
        $this->assertSame([], $state['bids']);
        $this->assertNull($state['action']);
        $this->assertArrayHasKey('server_now', $state);
    }

    public function test_the_lot_block_exposes_only_public_fields(): void
    {
        $leader = Participant::factory()->create(['name' => 'Ana']);
        $room = AuctionRoom::factory()->create();
        $lot = Lot::factory()->for($room, 'room')->create([
            'title' => 'Reloj antiguo',
            'start_price_cents' => 5_000,
            'current_price_cents' => null,
            'leader_participant_id' => $leader->id,
        ]);
        $room->update(['current_lot_id' => $lot->id, 'version' => 3]);

        $state = (new AuctionSnapshot($room->fresh()))->build();
        $publicLot = $state['lot'];

        $this->assertSame(3, $state['version']);
        $this->assertSame('Reloj antiguo', $publicLot['title']);
        $this->assertSame('Ana', $publicLot['leader_name']);
        // Sin pujas todavía, la próxima puja iguala el precio inicial (4.1).
        $this->assertNull($publicLot['current_price_cents']);
        $this->assertSame(5_000, $publicLot['reference_price_cents']);
        $this->assertSame('open', $publicLot['status']);
        $this->assertGreaterThanOrEqual(0, $publicLot['remaining_ms']);

        foreach (self::INTERNAL_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $publicLot);
        }
    }

    public function test_the_broadcast_payload_never_includes_the_viewer_block(): void
    {
        $room = AuctionRoom::factory()->create();
        $lot = Lot::factory()->for($room, 'room')->create();
        $room->update(['current_lot_id' => $lot->id]);

        $state = (new AuctionSnapshot($room->fresh()))->build();

        $this->assertArrayNotHasKey('me', $state);
        $this->assertSame(
            ['version', 'server_now', 'lot', 'bids', 'action', 'correlation_id'],
            array_keys($state)
        );
    }

    public function test_the_viewer_block_carries_own_balance_and_role(): void
    {
        $ana = Participant::factory()->create(['name' => 'Ana', 'balance_cents' => 90_000]);
        $room = AuctionRoom::factory()->create();
        $lot = Lot::factory()->for($room, 'room')->create(['leader_participant_id' => $ana->id]);
        $room->update(['current_lot_id' => $lot->id]);

        $snapshot = new AuctionSnapshot($room->fresh());

        $asAna = $snapshot->forViewer($ana, isHost: false);
        $this->assertSame('Ana', $asAna['me']['name']);
        $this->assertSame(90_000, $asAna['me']['balance_cents']);
        $this->assertTrue($asAna['me']['is_leader']);
        $this->assertFalse($asAna['me']['is_host']);

        // Anónimo: no inventa identidad.
        $anonymous = $snapshot->forViewer(null, isHost: false);
        $this->assertNull($anonymous['me']['name']);
        $this->assertNull($anonymous['me']['balance_cents']);
        $this->assertFalse($anonymous['me']['is_leader']);
    }

    public function test_a_closed_lot_reports_its_winner_by_name(): void
    {
        $winner = Participant::factory()->create(['name' => 'Beto']);
        $room = AuctionRoom::factory()->create();
        $lot = Lot::factory()->for($room, 'room')->closed()->create([
            'status' => LotStatus::Sold,
            'winner_participant_id' => $winner->id,
        ]);
        $room->update(['current_lot_id' => $lot->id]);

        $publicLot = (new AuctionSnapshot($room->fresh()))->build()['lot'];

        $this->assertSame('sold', $publicLot['status']);
        $this->assertSame('Beto', $publicLot['winner_name']);
        $this->assertArrayNotHasKey('winner_participant_id', $publicLot);
    }
}
