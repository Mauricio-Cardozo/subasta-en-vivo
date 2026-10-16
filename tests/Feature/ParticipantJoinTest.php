<?php

namespace Tests\Feature;

use App\Models\Participant;
use App\Services\SessionIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ParticipantJoinTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_bidder_joins_with_a_name_and_the_configured_balance(): void
    {
        $response = $this->postJson('/join', ['name' => 'Ana']);

        $response->assertCreated()
            ->assertJson([
                'participant' => [
                    'name' => 'Ana',
                    'balance_cents' => config('auction.participant.starting_balance_cents'),
                ],
            ]);

        $participant = Participant::query()->sole();

        $this->assertSame('Ana', $participant->name);
        $this->assertSame(config('auction.participant.starting_balance_cents'), $participant->balance_cents);
    }

    public function test_the_stored_session_reference_is_a_pseudonym_of_the_live_session(): void
    {
        $this->postJson('/join', ['name' => 'Ana'])->assertCreated();

        $participant = Participant::query()->sole();
        $identity = app(SessionIdentity::class);

        // El vínculo con el navegador se guarda hasheado, no como id de sesión.
        $this->assertNotSame(session()->getId(), $participant->session_id);
        $this->assertSame($identity->hash(request()), $participant->session_id);
    }

    public function test_the_name_is_trimmed_before_storing(): void
    {
        $this->postJson('/join', ['name' => '  Ana  '])->assertCreated();

        $this->assertSame('Ana', Participant::query()->sole()->name);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function invalidNames(): array
    {
        return [
            'reservado' => ['Subastador'],
            'reservado en minúsculas' => ['subastador'],
            'reservado con mayúsculas raras' => ['SUBASTADOR'],
            'demasiado corto' => ['Ab'],
            'demasiado largo' => [str_repeat('a', 21)],
            'caracteres raros' => ['Ana<script>'],
        ];
    }

    /**
     * @dataProvider invalidNames
     */
    public function test_invalid_names_are_rejected(string $name): void
    {
        $this->postJson('/join', ['name' => $name])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->assertSame(0, Participant::query()->count());
    }

    public function test_a_name_already_in_use_is_rejected_ignoring_case(): void
    {
        $this->postJson('/join', ['name' => 'Ana'])->assertCreated();

        $this->postJson('/join', ['name' => 'ana'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->assertSame(1, Participant::query()->count());
    }

    public function test_the_middleware_blocks_actions_that_require_an_identity(): void
    {
        Route::middleware(['web', 'participant'])->post('/probe', fn () => response()->json(['ok' => true]));

        $this->postJson('/probe')->assertForbidden();

        $this->postJson('/join', ['name' => 'Ana'])->assertCreated();

        $this->postJson('/probe')->assertOk()->assertJson(['ok' => true]);
    }

    public function test_joining_again_replaces_the_previous_identity(): void
    {
        $this->postJson('/join', ['name' => 'Ana'])->assertCreated();
        $first = Participant::query()->sole();

        $this->postJson('/join', ['name' => 'Beto'])->assertCreated();

        $this->assertSame(2, Participant::query()->count());
        $this->assertNotSame($first->session_id, Participant::query()->where('name', 'Beto')->sole()->session_id);
    }
}
