<?php

namespace Tests\Feature;

use App\Enums\LotStatus;
use App\Models\AuctionRoom;
use App\Models\Bid;
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
        $this->assertSame([], $state['history']);
        $this->assertSame(
            ['opens_at' => null, 'opens_in_ms' => null, 'items' => []],
            $state['queue']
        );
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
            ['version', 'server_now', 'lot', 'bids', 'history', 'queue', 'action', 'correlation_id'],
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

    public function test_the_bids_block_lists_the_most_recent_first(): void
    {
        $room = AuctionRoom::factory()->create();
        $lot = Lot::factory()->for($room, 'room')->create();
        $room->update(['current_lot_id' => $lot->id]);

        $ana = Participant::factory()->create(['name' => 'Ana']);
        $beto = Participant::factory()->create(['name' => 'Beto']);

        Bid::factory()->create(['lot_id' => $lot->id, 'participant_id' => $ana->id, 'amount_cents' => 5_000]);
        Bid::factory()->create(['lot_id' => $lot->id, 'participant_id' => $beto->id, 'amount_cents' => 5_100]);

        $bids = (new AuctionSnapshot($room->fresh()))->build()['bids'];

        $this->assertCount(2, $bids);
        $this->assertSame('Beto', $bids[0]['author_name']);
        $this->assertSame(5_100, $bids[0]['amount_cents']);
        $this->assertSame('Ana', $bids[1]['author_name']);
        $this->assertArrayHasKey('at', $bids[0]);
    }

    public function test_the_bids_block_is_capped_by_config(): void
    {
        $room = AuctionRoom::factory()->create();
        $lot = Lot::factory()->for($room, 'room')->create();
        $room->update(['current_lot_id' => $lot->id]);
        $ana = Participant::factory()->create();

        $cap = (int) config('auction.snapshot.bids');
        Bid::factory()->count($cap + 5)->create(['lot_id' => $lot->id, 'participant_id' => $ana->id]);

        $this->assertCount($cap, (new AuctionSnapshot($room->fresh()))->build()['bids']);
    }

    public function test_the_bids_block_never_leaks_internal_ids(): void
    {
        $room = AuctionRoom::factory()->create();
        $lot = Lot::factory()->for($room, 'room')->create();
        $room->update(['current_lot_id' => $lot->id]);
        $ana = Participant::factory()->create(['name' => 'Ana']);

        Bid::factory()->create(['lot_id' => $lot->id, 'participant_id' => $ana->id]);

        $bid = (new AuctionSnapshot($room->fresh()))->build()['bids'][0];

        $this->assertSame(['author_name', 'amount_cents', 'at'], array_keys($bid));
        $this->assertArrayNotHasKey('participant_id', $bid);
        $this->assertArrayNotHasKey('session_id', $bid);
        $this->assertArrayNotHasKey('balance_cents', $bid);
        $this->assertArrayNotHasKey('id', $bid);
    }

    public function test_a_lot_without_bids_reports_an_empty_list(): void
    {
        $room = AuctionRoom::factory()->create();
        $lot = Lot::factory()->for($room, 'room')->create();
        $room->update(['current_lot_id' => $lot->id]);

        $this->assertSame([], (new AuctionSnapshot($room->fresh()))->build()['bids']);
    }

    public function test_the_history_block_lists_closed_lots_newest_first(): void
    {
        $room = AuctionRoom::factory()->create();
        $winner = Participant::factory()->create(['name' => 'Beto']);

        // Viejo (sin vender) y nuevo (vendido): el lote abierto queda afuera.
        Lot::factory()->for($room, 'room')->closed()->create([
            'title' => 'Lote viejo',
            'status' => LotStatus::Unsold,
            'current_price_cents' => null,
        ]);
        Lot::factory()->for($room, 'room')->closed()->create([
            'title' => 'Lote nuevo',
            'status' => LotStatus::Sold,
            'current_price_cents' => 7_500,
            'winner_participant_id' => $winner->id,
        ]);
        $open = Lot::factory()->for($room, 'room')->create(['title' => 'Lote abierto']);
        $room->update(['current_lot_id' => $open->id]);

        $history = (new AuctionSnapshot($room->fresh()))->build()['history'];

        $this->assertCount(2, $history);
        $this->assertSame('Lote nuevo', $history[0]['title']);
        $this->assertSame('sold', $history[0]['status']);
        $this->assertSame(7_500, $history[0]['final_price_cents']);
        $this->assertSame('Beto', $history[0]['winner_name']);
        $this->assertNotNull($history[0]['closed_at']);

        $this->assertSame('Lote viejo', $history[1]['title']);
        $this->assertSame('unsold', $history[1]['status']);
        $this->assertNull($history[1]['final_price_cents']);
        $this->assertNull($history[1]['winner_name']);
    }

    public function test_the_history_block_is_capped_by_config(): void
    {
        $room = AuctionRoom::factory()->create();
        $cap = (int) config('auction.history.limit');

        Lot::factory()->for($room, 'room')->closed()->count($cap + 3)->create();

        $this->assertCount($cap, (new AuctionSnapshot($room->fresh()))->build()['history']);
    }

    public function test_the_history_block_never_leaks_internal_ids(): void
    {
        $room = AuctionRoom::factory()->create();
        Lot::factory()->for($room, 'room')->closed()->create();

        $entry = (new AuctionSnapshot($room->fresh()))->build()['history'][0];

        $this->assertSame(
            ['title', 'status', 'final_price_cents', 'winner_name', 'closed_at'],
            array_keys($entry)
        );

        foreach (self::INTERNAL_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $entry);
        }
    }

    public function test_the_queue_block_lists_lots_in_order_with_public_fields(): void
    {
        $room = AuctionRoom::factory()->create();
        $open = Lot::factory()->for($room, 'room')->create(['title' => 'Abierto']);
        $room->update(['current_lot_id' => $open->id, 'version' => 1]);

        $first = Lot::factory()->for($room, 'room')->queued()->create([
            'title' => 'Primero',
            'start_price_cents' => 1_000,
        ]);
        Lot::factory()->for($room, 'room')->queued()->create([
            'title' => 'Segundo',
            'start_price_cents' => 2_000,
        ]);

        $queue = (new AuctionSnapshot($room->fresh()))->build()['queue'];

        $this->assertNull($queue['opens_at']);
        $this->assertNull($queue['opens_in_ms']);
        $this->assertSame(['Primero', 'Segundo'], array_column($queue['items'], 'title'));
        $this->assertSame($first->id, $queue['items'][0]['id']);
        $this->assertSame(1_000, $queue['items'][0]['start_price_cents']);

        // Solo lo público: los items cargan id, título y precio base.
        $this->assertSame(['id', 'title', 'start_price_cents'], array_keys($queue['items'][0]));
    }

    public function test_the_queue_block_is_capped_by_config(): void
    {
        config()->set('auction.queue.max', 2);

        $room = AuctionRoom::factory()->create();
        Lot::factory()->for($room, 'room')->queued()->count(4)->create();

        $this->assertCount(2, (new AuctionSnapshot($room->fresh()))->build()['queue']['items']);
    }

    public function test_the_queue_block_reports_the_open_mark_during_the_pause(): void
    {
        $room = AuctionRoom::factory()->create([
            'queue_opens_at' => now()->addSeconds(5),
        ]);

        $queue = (new AuctionSnapshot($room->fresh()))->build()['queue'];

        $this->assertNotNull($queue['opens_at']);
        $this->assertGreaterThan(0, $queue['opens_in_ms']);
        $this->assertLessThanOrEqual(5_000, $queue['opens_in_ms']);
    }

    public function test_the_history_excludes_queued_and_cancelled_lots(): void
    {
        $room = AuctionRoom::factory()->create();

        Lot::factory()->for($room, 'room')->queued()->create(['title' => 'En cola']);
        Lot::factory()->for($room, 'room')->closed()->create([
            'title' => 'Quitado',
            'status' => LotStatus::Cancelled,
        ]);
        Lot::factory()->for($room, 'room')->closed()->create([
            'title' => 'Vendido',
            'status' => LotStatus::Sold,
        ]);

        $history = (new AuctionSnapshot($room->fresh()))->build()['history'];

        $this->assertSame(['Vendido'], array_column($history, 'title'));
    }
}
