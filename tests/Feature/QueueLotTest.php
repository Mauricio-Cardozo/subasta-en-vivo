<?php

namespace Tests\Feature;

use App\Enums\LotStatus;
use App\Models\AuctionRoom;
use App\Models\Lot;
use App\Services\AuctionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cola de lotes (Tarea 2): orden FIFO, tope y forma pública en el estado.
 */
class QueueLotTest extends TestCase
{
    use RefreshDatabase;

    private AuctionService $auction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->auction = app(AuctionService::class);
    }

    /**
     * Sala con un lote abierto y su versión, como la deja el subastador.
     */
    private function roomWithOpenLot(): AuctionRoom
    {
        $room = AuctionRoom::factory()->create(['version' => 1]);
        $lot = Lot::factory()->for($room, 'room')->create(['title' => 'Abierto']);
        $room->update(['current_lot_id' => $lot->id, 'version' => 1]);

        return $room->fresh();
    }

    public function test_lots_wait_their_turn_in_fifo_order(): void
    {
        $room = $this->roomWithOpenLot();

        $first = $this->auction->openLot('Primero', null, 1_000, 100, 60);
        $second = $this->auction->openLot('Segundo', null, 2_000, 100, 60);

        $this->assertTrue($first->ok);
        $this->assertTrue($second->ok);

        $queued = Lot::query()
            ->where('status', LotStatus::Queued->value)
            ->orderBy('id')
            ->pluck('title')
            ->all();

        $this->assertSame(['Primero', 'Segundo'], $queued);
        // Una versión por cada encolado.
        $this->assertSame(3, $room->fresh()->version);
    }

    public function test_the_queue_is_capped_by_config(): void
    {
        config()->set('auction.queue.max', 2);

        $room = $this->roomWithOpenLot();

        $this->assertTrue($this->auction->openLot('Primero', null, 1_000, 100, 60)->ok);
        $this->assertTrue($this->auction->openLot('Segundo', null, 1_000, 100, 60)->ok);

        $rejected = $this->auction->openLot('Tercero', null, 1_000, 100, 60);

        $this->assertFalse($rejected->ok);
        $this->assertSame('queue_full', $rejected->reason);

        // El rechazo no crea fila ni sube la versión.
        $this->assertSame(3, Lot::query()->count());
        $this->assertSame(3, $room->fresh()->version);
    }

    public function test_a_queued_lot_keeps_its_duration_until_it_opens(): void
    {
        $this->roomWithOpenLot();

        $this->auction->openLot('Lento', null, 1_000, 100, 120);

        $queued = Lot::query()->where('status', LotStatus::Queued->value)->sole();

        $this->assertSame(120, $queued->duration_seconds);
        $this->assertNull($queued->started_at);
        $this->assertNull($queued->ends_at);
    }

    public function test_the_state_exposes_the_queue_with_public_fields(): void
    {
        $this->roomWithOpenLot();

        $this->auction->openLot('Primero', null, 1_000, 100, 60);
        $this->auction->openLot('Segundo', null, 2_500, 100, 60);

        $response = $this->getJson('/auction/state')->assertOk();

        $response->assertJsonPath('queue.opens_at', null)
            ->assertJsonPath('queue.opens_in_ms', null)
            ->assertJsonPath('queue.items.0.title', 'Primero')
            ->assertJsonPath('queue.items.0.start_price_cents', 1_000)
            ->assertJsonPath('queue.items.1.title', 'Segundo');

        $this->assertSame(
            ['id', 'title', 'start_price_cents'],
            array_keys($response->json('queue.items.0'))
        );
    }
}
