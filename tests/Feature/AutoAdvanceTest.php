<?php

namespace Tests\Feature;

use App\Enums\LotStatus;
use App\Events\AuctionStateUpdated;
use App\Models\AuctionRoom;
use App\Models\Lot;
use App\Services\AuctionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Auto-avance de la cola (Tarea 2): pausa tras cada cierre y apertura perezosa
 * del siguiente lote desde la lectura de estado.
 */
class AutoAdvanceTest extends TestCase
{
    use RefreshDatabase;

    private AuctionRoom $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->room = AuctionRoom::factory()->create(['version' => 1]);
        $open = Lot::factory()->for($this->room, 'room')->create(['title' => 'Abierto']);
        $this->room->update(['current_lot_id' => $open->id, 'version' => 1]);
    }

    private function queuedLot(string $title, int $durationSeconds = 60): Lot
    {
        return Lot::factory()->for($this->room, 'room')->queued()->create([
            'title' => $title,
            'duration_seconds' => $durationSeconds,
        ]);
    }

    public function test_closing_with_a_queue_sets_the_pause_and_keeps_the_result(): void
    {
        $this->queuedLot('Siguiente');

        $result = app(AuctionService::class)->closeCurrentLot();

        $this->assertTrue($result->ok);

        $room = $this->room->fresh();
        $gap = (int) config('auction.queue.gap_seconds');

        $this->assertNotNull($room->queue_opens_at);
        $this->assertTrue($room->queue_opens_at->greaterThan(now()->addSeconds($gap - 2)));
        $this->assertTrue($room->queue_opens_at->lessThan(now()->addSeconds($gap + 2)));

        // Una sola versión: el cierre y la pausa son la misma transición.
        $this->assertSame(2, $room->version);

        $state = $this->getJson('/auction/state')->assertOk();

        // El resultado del lote anterior sigue a la vista.
        $this->assertNotSame('open', $state->json('lot.status'));
        $this->assertSame('Abierto', $state->json('lot.title'));

        // Y la pausa viaja como marca del servidor, igual que el restante del lote.
        $this->assertGreaterThan(0, $state->json('queue.opens_in_ms'));
        $this->assertLessThanOrEqual($gap * 1000, $state->json('queue.opens_in_ms'));
        $this->assertSame('Siguiente', $state->json('queue.items.0.title'));
    }

    public function test_the_state_before_the_pause_ends_does_not_open_the_next_lot(): void
    {
        $queued = $this->queuedLot('Siguiente');

        app(AuctionService::class)->closeCurrentLot();

        $this->getJson('/auction/state')->assertOk();

        $this->assertSame(LotStatus::Queued, $queued->fresh()->status);
        $this->assertSame(2, $this->room->fresh()->version);
    }

    public function test_the_state_read_opens_the_next_lot_when_the_pause_ends(): void
    {
        $queued = $this->queuedLot('Siguiente', durationSeconds: 120);

        app(AuctionService::class)->closeCurrentLot();

        // La pausa ya venció (se viaja en el tiempo, no se espera de verdad).
        $this->travel((int) config('auction.queue.gap_seconds') + 1)->seconds();

        Event::fake([AuctionStateUpdated::class]);

        $this->getJson('/auction/state')
            ->assertOk()
            ->assertJsonPath('lot.title', 'Siguiente')
            ->assertJsonPath('lot.status', 'open')
            ->assertJsonPath('queue.items', []);

        $fresh = $queued->fresh();

        $this->assertSame(LotStatus::Open, $fresh->status);
        $this->assertSame($this->room->fresh()->current_lot_id, $fresh->id);
        // Abre con la duración elegida al cargarlo (no con la pausa).
        $this->assertSame(120, (int) $fresh->started_at->diffInSeconds($fresh->ends_at));

        // Una sola transición más: cierre (2) y apertura (3).
        $this->assertSame(3, $this->room->fresh()->version);

        Event::assertDispatched(
            AuctionStateUpdated::class,
            fn (AuctionStateUpdated $event): bool => $event->broadcastWith()['action']['type'] === 'lot_opened'
        );
    }

    public function test_closing_the_last_lot_leaves_no_pause(): void
    {
        $result = app(AuctionService::class)->closeCurrentLot();

        $this->assertTrue($result->ok);
        $this->assertNull($this->room->fresh()->queue_opens_at);

        $this->getJson('/auction/state')
            ->assertOk()
            ->assertJsonPath('queue.opens_in_ms', null)
            ->assertJsonPath('queue.items', []);
    }

    public function test_the_expiry_of_the_last_lot_also_leaves_no_pause(): void
    {
        // El lote vence: la lectura de estado lo liquida y, sin cola, no hay
        // pausa pendiente.
        $this->room->currentLot->forceFill(['ends_at' => now()->subSecond()])->save();

        $this->getJson('/auction/state')->assertOk();

        $this->assertSame(LotStatus::Unsold, $this->room->currentLot->fresh()->status);
        $this->assertNull($this->room->fresh()->queue_opens_at);
    }
}
