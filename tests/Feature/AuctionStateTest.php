<?php

namespace Tests\Feature;

use App\Models\AuctionRoom;
use App\Models\Lot;
use App\Models\Participant;
use App\Services\AuctionSnapshot;
use Database\Seeders\AuctionRoomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuctionStateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $lot
     */
    private function openLot(array $lot = []): AuctionRoom
    {
        $this->seed(AuctionRoomSeeder::class);

        $room = AuctionRoom::query()->sole();
        $created = Lot::factory()->for($room, 'room')->create($lot);
        $room->update(['current_lot_id' => $created->id, 'version' => 1]);

        return $room->fresh();
    }

    private function asHost(): void
    {
        config()->set('auction.host_pin', 'pin-secreto');
        $this->postJson('/host/login', ['pin' => 'pin-secreto'])->assertOk();
    }

    public function test_the_state_is_public_and_never_cached(): void
    {
        $this->openLot(['title' => 'Reloj antiguo']);

        $response = $this->getJson('/auction/state');

        $response->assertOk()
            ->assertJsonPath('version', 1)
            ->assertJsonPath('lot.title', 'Reloj antiguo')
            ->assertJsonPath('me.name', null);

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_an_empty_room_reports_a_zero_version(): void
    {
        $this->getJson('/auction/state')
            ->assertOk()
            ->assertJsonPath('version', 0)
            ->assertJsonPath('lot', null);
    }

    public function test_the_payload_matches_the_broadcast_shape(): void
    {
        $room = $this->openLot();

        $expected = (new AuctionSnapshot($room))->build();
        $state = $this->getJson('/auction/state')->assertOk()->json();

        // Mismas claves que el broadcast, más el bloque propio (`me`), y los
        // mismos valores estables: `remaining_ms` se mide al construir cada
        // payload.
        $this->assertSame([...array_keys($expected), 'me'], array_keys($state));
        $this->assertSame(array_keys($expected['lot']), array_keys($state['lot']));
        $this->assertSame($expected['version'], $state['version']);
        $this->assertSame($expected['lot']['id'], $state['lot']['id']);
        $this->assertSame($expected['lot']['title'], $state['lot']['title']);
        $this->assertSame($expected['lot']['ends_at'], $state['lot']['ends_at']);
        $this->assertSame([], $state['bids']);

        $this->assertGreaterThan(0, $state['lot']['remaining_ms']);
        $this->assertLessThanOrEqual(
            config('auction.lot.duration_seconds') * 1000,
            $state['lot']['remaining_ms']
        );
    }

    public function test_a_bidder_sees_their_own_balance_but_only_their_own(): void
    {
        $this->openLot();

        $this->postJson('/join', ['name' => 'Ana'])->assertCreated();

        $response = $this->getJson('/auction/state')->assertOk();

        $response->assertJsonPath('me.name', 'Ana')
            ->assertJsonPath('me.balance_cents', config('auction.participant.starting_balance_cents'))
            ->assertJsonPath('me.is_host', false)
            ->assertJsonPath('me.is_leader', false);
    }

    public function test_the_host_is_recognised_in_the_state(): void
    {
        $this->openLot();
        $this->asHost();

        $this->getJson('/auction/state')
            ->assertOk()
            ->assertJsonPath('me.is_host', true)
            ->assertJsonPath('me.name', null);
    }

    public function test_the_state_never_exposes_internal_identifiers_or_other_balances(): void
    {
        $leader = Participant::factory()->create(['name' => 'Ana', 'balance_cents' => 12_345]);

        $room = $this->openLot(['leader_participant_id' => $leader->id]);
        $this->assertSame($leader->id, $room->currentLot->leader_participant_id);

        $payload = $this->getJson('/auction/state')->assertOk()->json();
        $encoded = json_encode($payload);

        // Ni ids internos, ni datos de sesión, ni saldos ajenos.
        $this->assertStringNotContainsString($leader->session_id, $encoded);
        $this->assertStringNotContainsString('leader_participant_id', $encoded);
        $this->assertStringNotContainsString('winner_participant_id', $encoded);
        $this->assertStringNotContainsString('session_id', $encoded);
        $this->assertStringNotContainsString('12.345', $encoded);
        $this->assertSame('Ana', $payload['lot']['leader_name']);
    }
}
