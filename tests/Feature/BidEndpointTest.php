<?php

namespace Tests\Feature;

use App\Models\AuctionAction;
use App\Models\AuctionRoom;
use App\Models\Bid;
use App\Models\Lot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POST /auction/bids: identidad, puja e idempotencia (RF-04, 4.7).
 *
 * Las altas van por `POST /join` de verdad para que la sesión (y por lo tanto
 * la clave de idempotencia) sea la misma entre peticiones, como en el
 * navegador.
 */
class BidEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $room = AuctionRoom::factory()->create();
        $lot = Lot::factory()->for($room, 'room')->create([
            'start_price_cents' => 5_000,
            'min_increment_cents' => 100,
        ]);
        $room->update(['current_lot_id' => $lot->id]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function payload(array $payload = []): array
    {
        return array_merge(['action_id' => 'accion-1'], $payload);
    }

    private function join(string $name): void
    {
        $this->postJson('/join', ['name' => $name])->assertCreated();
    }

    public function test_a_bid_requires_an_identity(): void
    {
        $this->postJson('/auction/bids', $this->payload())
            ->assertForbidden();

        $this->assertSame(0, Bid::query()->count());
    }

    public function test_a_joined_bidder_places_a_bid(): void
    {
        $this->join('Ana');

        $response = $this->postJson('/auction/bids', $this->payload())
            ->assertCreated()
            ->assertJson([
                'amount_cents' => 5_000,
                'version' => 1,
            ]);

        $this->assertIsInt($response->json('bid_id'));
        $this->assertSame(1, Bid::query()->count());
        $this->assertSame('Ana', Lot::query()->sole()->leader->name);
    }

    public function test_the_same_action_id_does_not_bid_twice(): void
    {
        $this->join('Ana');

        $first = $this->postJson('/auction/bids', $this->payload())->assertCreated();
        $second = $this->postJson('/auction/bids', $this->payload())->assertCreated();

        // La respuesta es idéntica: se repite la original, no se pujó de nuevo.
        $this->assertSame($first->json(), $second->json());
        $this->assertSame(1, Bid::query()->count());
        $this->assertSame(1, AuctionAction::query()->count());
        $this->assertSame(1, AuctionRoom::query()->sole()->version);
    }

    public function test_a_rejected_bid_is_replayed_with_the_same_status_and_body(): void
    {
        $this->join('Ana');

        $payload = $this->payload(['amount_cents' => 10]);

        $first = $this->postJson('/auction/bids', $payload)
            ->assertStatus(422)
            ->assertJson(['reason' => 'bid_too_low', 'minimum_cents' => 5_000]);

        $second = $this->postJson('/auction/bids', $payload)->assertStatus(422);

        // El rechazo también es idempotente: mismo cuerpo, cero pujas.
        $this->assertSame($first->json(), $second->json());
        $this->assertSame(0, Bid::query()->count());
        $this->assertSame(1, AuctionAction::query()->count());
    }

    public function test_two_bidders_can_bid_in_turn(): void
    {
        $this->join('Ana');
        $this->postJson('/auction/bids', $this->payload(['action_id' => 'de-ana']))->assertCreated();

        // Cambiar de identidad invalida la sesión, así que es otro pujador.
        $this->join('Beto');

        $this->postJson('/auction/bids', $this->payload(['action_id' => 'de-beto']))
            ->assertCreated()
            ->assertJson(['amount_cents' => 5_100, 'version' => 2]);

        $this->assertSame(2, Bid::query()->count());
        $this->assertSame('Beto', Lot::query()->sole()->leader->name);
    }

    public function test_the_same_bidder_cannot_bid_twice_with_a_new_action(): void
    {
        $this->join('Ana');

        $this->postJson('/auction/bids', $this->payload(['action_id' => 'a']))->assertCreated();

        $this->postJson('/auction/bids', $this->payload(['action_id' => 'b']))
            ->assertStatus(422)
            ->assertJson(['reason' => 'already_leading']);

        $this->assertSame(1, Bid::query()->count());
    }

    public function test_the_action_id_is_required(): void
    {
        $this->join('Ana');

        $this->postJson('/auction/bids', ['amount_cents' => 5_000])
            ->assertStatus(422)
            ->assertJsonValidationErrors('action_id');
    }

    public function test_bids_are_throttled_per_second(): void
    {
        $this->join('Ana');

        // Los tres primeros pasan el límite (pujan o son rechazados por
        // auto-puja); el cuarto ya no llega al controlador.
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $status = $this->postJson('/auction/bids', $this->payload(['action_id' => "accion-{$attempt}"]))->status();

            $this->assertNotSame(429, $status, "El intento {$attempt} ya estaba limitado.");
        }

        $this->postJson('/auction/bids', $this->payload(['action_id' => 'accion-4']))
            ->assertStatus(429);
    }
}
