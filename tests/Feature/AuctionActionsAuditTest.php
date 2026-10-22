<?php

namespace Tests\Feature;

use App\Enums\LotStatus;
use App\Events\AuctionStateUpdated;
use App\Models\AuctionAction;
use App\Models\AuctionRoom;
use App\Models\Lot;
use App\Models\Participant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Auditoría persistente en `auction_actions` (RF-15, 5.3).
 *
 * Cada acción, aceptada o rechazada, deja una fila con el tipo, el
 * participante, la IP, el `correlation_id` y los datos de la acción. Los
 * cierres quedan como transición propia (`lot_closed`).
 */
class AuctionActionsAuditTest extends TestCase
{
    use RefreshDatabase;

    private Lot $lot;

    protected function setUp(): void
    {
        parent::setUp();

        $room = AuctionRoom::factory()->create();
        $this->lot = Lot::factory()->for($room, 'room')->create([
            'start_price_cents' => 5_000,
            'min_increment_cents' => 100,
        ]);
        $room->update(['current_lot_id' => $this->lot->id, 'version' => 1]);
    }

    private function asHost(): void
    {
        config()->set('auction.host_pin', 'pin-secreto');
        $this->postJson('/host/login', ['pin' => 'pin-secreto'])->assertOk();
    }

    public function test_an_accepted_bid_is_recorded_with_who_where_and_what(): void
    {
        $this->postJson('/join', ['name' => 'Ana'])->assertCreated();

        $response = $this->withHeader('X-Correlation-ID', 'cid-puja')
            ->postJson('/auction/bids', ['action_id' => 'p1', 'amount_cents' => 5_000])
            ->assertCreated();

        $action = AuctionAction::query()->where('type', 'bid')->sole();

        $this->assertSame(Participant::query()->sole()->id, $action->participant_id);
        $this->assertSame('127.0.0.1', $action->ip);
        $this->assertSame('cid-puja', $action->correlation_id);
        $this->assertSame($response->headers->get('X-Correlation-ID'), $action->correlation_id);
        $this->assertSame('p1', $action->action_id);
        $this->assertSame(['amount_cents' => 5_000], $action->payload);
        $this->assertTrue($action->accepted);
        $this->assertNull($action->reason);
    }

    public function test_a_rejected_bid_is_recorded_with_its_reason(): void
    {
        $this->postJson('/join', ['name' => 'Ana'])->assertCreated();

        $this->postJson('/auction/bids', ['amount_cents' => 10])->assertStatus(422);

        $action = AuctionAction::query()->where('type', 'bid')->sole();

        $this->assertFalse($action->accepted);
        $this->assertSame('bid_too_low', $action->reason);
        $this->assertSame(422, $action->result['status']);
    }

    public function test_a_late_bid_is_rejected_but_the_close_it_triggers_is_recorded_and_published(): void
    {
        Event::fake([AuctionStateUpdated::class]);
        $this->postJson('/join', ['name' => 'Ana'])->assertCreated();
        $this->lot->update(['ends_at' => now()->subSecond()]);

        $this->postJson('/auction/bids', ['amount_cents' => 5_000])
            ->assertStatus(422)
            ->assertJson(['reason' => 'lot_expired']);

        // 4.5: el rechazo no deshace el cierre; los dos quedan registrados.
        $this->assertSame(LotStatus::Unsold, $this->lot->fresh()->status);
        $this->assertSame('lot_expired', AuctionAction::query()->where('type', 'bid')->sole()->reason);

        $closed = AuctionAction::query()->where('type', 'lot_closed')->sole();
        $this->assertSame(AuctionAction::SYSTEM_ACTOR, $closed->actor_key);
        $this->assertSame('time', $closed->payload['cause']);
        $this->assertSame('unsold', $closed->payload['status']);

        // Y el cierre se publica, sin ningún evento de puja aceptada.
        Event::assertDispatchedTimes(AuctionStateUpdated::class, 1);
        Event::assertDispatched(
            AuctionStateUpdated::class,
            fn (AuctionStateUpdated $event): bool => $event->snapshot['action']['type'] === 'lot_closed',
        );
    }

    public function test_opening_and_closing_by_the_host_are_recorded(): void
    {
        $this->asHost();

        $this->postJson('/auction/lots', ['title' => 'Jarrón', 'start_price_cents' => 1_000])->assertCreated();
        $this->postJson('/auction/lots/close', [])->assertCreated();

        $open = AuctionAction::query()->where('type', 'open_lot')->sole();
        $this->assertTrue($open->accepted);
        $this->assertSame('Jarrón', $open->payload['title']);
        $this->assertStringStartsWith('host:', $open->actor_key);
        $this->assertNull($open->participant_id);

        $this->assertTrue(AuctionAction::query()->where('type', 'close_lot')->sole()->accepted);
        $this->assertSame('manual', AuctionAction::query()->where('type', 'lot_closed')->sole()->payload['cause']);
    }

    public function test_the_payload_never_stores_the_pin(): void
    {
        config()->set('auction.host_pin', 'pin-secreto');
        $this->postJson('/host/login', ['pin' => 'pin-secreto'])->assertOk();
        $this->postJson('/auction/lots/close', [])->assertCreated();

        foreach (AuctionAction::query()->get() as $action) {
            $this->assertStringNotContainsString('pin-secreto', json_encode($action->toArray(), JSON_THROW_ON_ERROR));
        }
    }
}
