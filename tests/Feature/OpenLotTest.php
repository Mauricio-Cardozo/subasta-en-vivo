<?php

namespace Tests\Feature;

use App\Enums\LotStatus;
use App\Events\AuctionStateUpdated;
use App\Models\AuctionRoom;
use App\Models\Lot;
use Database\Seeders\AuctionRoomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class OpenLotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AuctionRoomSeeder::class);
        config()->set('auction.host_pin', 'pin-secreto');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            // Un `action_id` nuevo por intento: repetir el payload no repite la
            // acción (Tarea 2).
            'action_id' => Str::uuid()->toString(),
            'title' => 'Reloj antiguo',
            'description' => 'Funciona y anda bien',
            'start_price_cents' => 5_000,
        ], $overrides);
    }

    private function asHost(): void
    {
        $this->postJson('/host/login', ['pin' => 'pin-secreto'])->assertOk();
    }

    public function test_only_the_host_can_open_a_lot(): void
    {
        $this->postJson('/auction/lots', $this->payload())->assertForbidden();

        $this->asHost();

        $this->postJson('/auction/lots', $this->payload())->assertCreated();
    }

    public function test_the_host_needs_a_valid_pin(): void
    {
        $this->postJson('/host/login', ['pin' => 'incorrecto'])->assertStatus(422);

        $this->postJson('/auction/lots', $this->payload())->assertForbidden();
    }

    public function test_opening_a_lot_publishes_it_as_the_current_lot(): void
    {
        $this->asHost();

        $this->postJson('/auction/lots', $this->payload())
            ->assertCreated()
            ->assertJson(['version' => 1, 'status' => 'open']);

        $room = AuctionRoom::query()->sole();
        $lot = Lot::query()->sole();

        $this->assertSame(1, $room->version);
        $this->assertSame($lot->id, $room->current_lot_id);
        $this->assertSame(LotStatus::Open, $lot->status);
        $this->assertSame(5_000, $lot->start_price_cents);
        $this->assertNull($lot->current_price_cents);
        $this->assertSame(config('auction.lot.min_increment_cents'), $lot->min_increment_cents);
    }

    public function test_the_lot_ends_after_the_configured_duration(): void
    {
        $this->asHost();

        $this->postJson('/auction/lots', $this->payload(['duration_seconds' => 90]))->assertCreated();

        $lot = Lot::query()->sole();

        $this->assertSame(90, (int) $lot->started_at->diffInSeconds($lot->ends_at));
        $this->assertSame(90, $lot->duration_seconds);
        $this->assertSame(0, $lot->extensions);
    }

    public function test_the_default_duration_comes_from_the_config(): void
    {
        $this->asHost();

        $this->postJson('/auction/lots', $this->payload())->assertCreated();

        $lot = Lot::query()->sole();

        $this->assertSame(
            config('auction.lot.duration_seconds'),
            (int) $lot->started_at->diffInSeconds($lot->ends_at)
        );
        $this->assertSame(config('auction.lot.duration_seconds'), $lot->duration_seconds);
    }

    public function test_a_second_lot_joins_the_queue_instead_of_opening(): void
    {
        $this->asHost();

        $this->postJson('/auction/lots', $this->payload())->assertCreated();

        $this->postJson('/auction/lots', $this->payload(['title' => 'Otro lote']))
            ->assertCreated()
            ->assertJson(['status' => 'queued', 'version' => 2]);

        $lots = Lot::query()->orderBy('id')->get();

        $this->assertCount(2, $lots);
        $this->assertSame(LotStatus::Open, $lots[0]->status);
        $this->assertSame(LotStatus::Queued, $lots[1]->status);
        $this->assertSame('Otro lote', $lots[1]->title);

        // Un lote encolado no arrancó ni tiene plazo todavía.
        $this->assertNull($lots[1]->started_at);
        $this->assertNull($lots[1]->ends_at);

        // El lote actual sigue siendo el abierto (el resultado se mantiene a la
        // vista durante la pausa).
        $this->assertSame($lots[0]->id, AuctionRoom::query()->sole()->current_lot_id);
    }

    public function test_a_closed_lot_does_not_block_the_next_one(): void
    {
        $this->asHost();

        $this->postJson('/auction/lots', $this->payload())->assertCreated();

        Lot::query()->sole()->update([
            'status' => LotStatus::Sold,
            'closed_at' => now(),
        ]);

        $this->postJson('/auction/lots', $this->payload(['title' => 'Segundo lote']))
            ->assertCreated()
            ->assertJson(['status' => 'open']);

        $this->assertSame(2, Lot::query()->count());
        $this->assertSame(2, AuctionRoom::query()->sole()->version);
    }

    public function test_the_lot_must_be_valid(): void
    {
        $this->asHost();

        $this->postJson('/auction/lots', $this->payload(['title' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('title');

        $this->postJson('/auction/lots', $this->payload(['start_price_cents' => 0]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('start_price_cents');

        $this->postJson('/auction/lots', $this->payload(['start_price_cents' => 'gratis']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('start_price_cents');

        $this->assertSame(0, Lot::query()->count());
    }

    public function test_the_event_carries_the_new_state_and_the_action(): void
    {
        Event::fake([AuctionStateUpdated::class]);

        $this->asHost();

        $this->postJson('/auction/lots', $this->payload())->assertCreated();

        Event::assertDispatched(AuctionStateUpdated::class, function (AuctionStateUpdated $event): bool {
            $payload = $event->broadcastWith();

            return $payload['version'] === 1
                && $payload['lot']['title'] === 'Reloj antiguo'
                && $payload['lot']['status'] === 'open'
                && $payload['action']['type'] === 'lot_opened'
                && $payload['bids'] === []
                && $payload['queue'] === ['opens_at' => null, 'opens_in_ms' => null, 'items' => []]
                && ! array_key_exists('me', $payload);
        });
    }

    public function test_a_rejected_open_does_not_emit_an_event(): void
    {
        config()->set('auction.queue.max', 1);

        $this->asHost();

        $this->postJson('/auction/lots', $this->payload())->assertCreated();
        $this->postJson('/auction/lots', $this->payload(['title' => 'En cola']))->assertCreated();

        Event::fake([AuctionStateUpdated::class]);

        $this->postJson('/auction/lots', $this->payload(['title' => 'De más']))
            ->assertStatus(422)
            ->assertJson(['reason' => 'queue_full']);

        Event::assertNotDispatched(AuctionStateUpdated::class);

        // El rechazo no toca el estado.
        $this->assertSame(2, Lot::query()->count());
        $this->assertSame(2, AuctionRoom::query()->sole()->version);
    }

    public function test_the_action_id_is_optional_to_open(): void
    {
        $this->asHost();

        // RNF-05: sin `action_id` la acción corre y se audita, pero no es
        // idempotente (dos envíos encolan dos lotes).
        $payload = ['title' => 'Reloj antiguo', 'start_price_cents' => 5_000];

        $this->postJson('/auction/lots', $payload)->assertCreated();
        $this->postJson('/auction/lots', $payload)->assertCreated();

        $this->assertSame(2, Lot::query()->count());
    }

    public function test_the_same_action_id_does_not_enqueue_twice(): void
    {
        $this->asHost();

        $this->postJson('/auction/lots', $this->payload(['title' => 'Reloj']))->assertCreated();

        $payload = $this->payload(['title' => 'En cola', 'action_id' => 'abrir-1']);

        $first = $this->postJson('/auction/lots', $payload)->assertCreated()->assertJson(['status' => 'queued']);
        $second = $this->postJson('/auction/lots', $payload)->assertCreated();

        // El reintento repite la respuesta original: mismo lote, misma versión.
        $this->assertSame($first->json(), $second->json());
        $this->assertSame(2, Lot::query()->count());
        $this->assertSame(1, Lot::query()->where('status', LotStatus::Queued->value)->count());
        $this->assertSame(2, AuctionRoom::query()->sole()->version);
    }
}
