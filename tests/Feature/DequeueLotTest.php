<?php

namespace Tests\Feature;

use App\Enums\LotStatus;
use App\Events\AuctionStateUpdated;
use App\Models\AuctionRoom;
use App\Models\Lot;
use App\Services\AuctionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * DELETE /auction/lots/{lot}: el subastador quita un lote de la cola (Tarea 2).
 *
 * La baja es idempotente por estado: repetirla no vuelve a transicionar.
 */
class DequeueLotTest extends TestCase
{
    use RefreshDatabase;

    private AuctionRoom $room;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('auction.host_pin', 'pin-secreto');

        $this->room = AuctionRoom::factory()->create(['version' => 1]);
        $open = Lot::factory()->for($this->room, 'room')->create(['title' => 'Abierto']);
        $this->room->update(['current_lot_id' => $open->id, 'version' => 1]);
    }

    private function queuedLot(string $title = 'En cola'): Lot
    {
        return Lot::factory()->for($this->room, 'room')->queued()->create(['title' => $title]);
    }

    private function asHost(): void
    {
        $this->postJson('/host/login', ['pin' => 'pin-secreto'])->assertOk();
    }

    public function test_only_the_host_can_remove_a_lot_from_the_queue(): void
    {
        $lot = $this->queuedLot();

        $this->deleteJson("/auction/lots/{$lot->id}")->assertForbidden();

        $this->assertSame(LotStatus::Queued, $lot->fresh()->status);
    }

    public function test_the_host_removes_a_queued_lot(): void
    {
        $lot = $this->queuedLot('Reloj de pie');
        $this->asHost();

        Event::fake([AuctionStateUpdated::class]);

        $this->deleteJson("/auction/lots/{$lot->id}")
            ->assertOk()
            ->assertJson([
                'lot_id' => $lot->id,
                'status' => 'cancelled',
                'version' => 2,
            ]);

        $fresh = $lot->fresh();
        $this->assertSame(LotStatus::Cancelled, $fresh->status);
        $this->assertNotNull($fresh->closed_at);
        $this->assertSame(2, $this->room->fresh()->version);

        Event::assertDispatched(
            AuctionStateUpdated::class,
            fn (AuctionStateUpdated $event): bool => $event->broadcastWith()['action']['type'] === 'lot_dequeued'
        );
    }

    public function test_removing_is_idempotent(): void
    {
        $lot = $this->queuedLot();
        $this->asHost();

        $first = $this->deleteJson("/auction/lots/{$lot->id}")->assertOk();

        Event::fake([AuctionStateUpdated::class]);

        $second = $this->deleteJson("/auction/lots/{$lot->id}")->assertOk();

        // El repetido repite la respuesta y no transiciona: sin versión nueva,
        // sin evento.
        $this->assertSame($first->json(), $second->json());
        $this->assertSame(2, $this->room->fresh()->version);
        Event::assertNotDispatched(AuctionStateUpdated::class);
    }

    public function test_a_lot_outside_the_queue_cannot_be_removed(): void
    {
        $this->asHost();

        $this->deleteJson("/auction/lots/{$this->room->current_lot_id}")
            ->assertStatus(422)
            ->assertJson(['reason' => 'lot_not_queued']);

        $sold = Lot::factory()->for($this->room, 'room')->closed()->create(['status' => LotStatus::Sold]);

        $this->deleteJson("/auction/lots/{$sold->id}")
            ->assertStatus(422)
            ->assertJson(['reason' => 'lot_not_queued']);
    }

    public function test_removing_the_last_queued_lot_during_the_pause_stops_the_clock(): void
    {
        $queued = $this->queuedLot();

        app(AuctionService::class)->closeCurrentLot();

        $this->assertNotNull($this->room->fresh()->queue_opens_at);

        $this->asHost();
        $this->deleteJson("/auction/lots/{$queued->id}")->assertOk();

        $room = $this->room->fresh();
        $this->assertNull($room->queue_opens_at);

        $this->getJson('/auction/state')
            ->assertOk()
            ->assertJsonPath('queue.opens_in_ms', null)
            ->assertJsonPath('queue.items', []);

        // Pasado el momento de la pausa original, no se abre nada.
        $versionAfterDelete = $room->version;
        $this->travel(30)->seconds();

        $state = $this->getJson('/auction/state')->assertOk();

        $this->assertNotSame('open', $state->json('lot.status'));
        $this->assertSame($versionAfterDelete, $state->json('version'));
    }

    public function test_removing_one_of_several_queued_lots_keeps_the_pause(): void
    {
        $first = $this->queuedLot('Primero');
        $this->queuedLot('Segundo');

        app(AuctionService::class)->closeCurrentLot();

        $this->asHost();
        $this->deleteJson("/auction/lots/{$first->id}")->assertOk();

        $this->assertNotNull($this->room->fresh()->queue_opens_at);

        $this->getJson('/auction/state')
            ->assertOk()
            ->assertJsonPath('queue.items.0.title', 'Segundo');
    }

    public function test_the_delete_endpoint_is_rate_limited(): void
    {
        config()->set('auction.limits.lot_deletes_per_minute', 2);
        RateLimiter::clear('lot-deletes:127.0.0.1');

        $first = $this->queuedLot('Primero');
        $second = $this->queuedLot('Segundo');
        $third = $this->queuedLot('Tercero');

        $this->asHost();

        $this->deleteJson("/auction/lots/{$first->id}")->assertOk();
        $this->deleteJson("/auction/lots/{$second->id}")->assertOk();
        $this->deleteJson("/auction/lots/{$third->id}")->assertStatus(429);
    }
}
