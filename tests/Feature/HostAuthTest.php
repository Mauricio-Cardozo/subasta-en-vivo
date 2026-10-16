<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class HostAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('auction.host_pin', 'pin-secreto');
    }

    public function test_the_host_logs_in_with_the_configured_pin(): void
    {
        $this->postJson('/host/login', ['pin' => 'pin-secreto'])
            ->assertOk()
            ->assertJson(['host' => true]);

        $this->assertTrue(session('is_host'));
    }

    public function test_a_wrong_pin_is_rejected_without_granting_the_role(): void
    {
        $this->postJson('/host/login', ['pin' => 'no-es-el-pin'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pin');

        $this->assertNull(session('is_host'));
    }

    public function test_a_blank_configured_pin_never_grants_the_role(): void
    {
        config()->set('auction.host_pin', '');

        $this->postJson('/host/login', ['pin' => ''])
            ->assertStatus(422);

        $this->assertNull(session('is_host'));
    }

    public function test_the_pin_is_required(): void
    {
        $this->postJson('/host/login', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pin');
    }

    public function test_the_host_role_protects_host_actions(): void
    {
        Route::middleware(['web', 'host'])->post('/probe', fn () => response()->json(['ok' => true]));

        // Sin PIN: 403 y sin filtrar nada.
        $this->postJson('/probe')->assertForbidden();

        $this->postJson('/host/login', ['pin' => 'pin-secreto'])->assertOk();

        $this->postJson('/probe')->assertOk()->assertJson(['ok' => true]);
    }

    public function test_logging_out_drops_the_host_role(): void
    {
        Route::middleware(['web', 'host'])->post('/probe', fn () => response()->json(['ok' => true]));

        $this->postJson('/host/login', ['pin' => 'pin-secreto'])->assertOk();
        $this->postJson('/host/logout')->assertOk()->assertJson(['host' => false]);

        $this->assertNull(session('is_host'));
        $this->postJson('/probe')->assertForbidden();
    }

    public function test_logging_in_renews_the_session_and_drops_any_previous_identity(): void
    {
        $this->postJson('/join', ['name' => 'Ana'])->assertCreated();

        $this->assertNotNull(session('participant_id'));

        $this->postJson('/host/login', ['pin' => 'pin-secreto'])->assertOk();

        // Cambiar de rol no conserva los privilegios (ni la identidad) previos.
        $this->assertNull(session('participant_id'));
        $this->assertTrue(session('is_host'));
    }

    public function test_repeated_pin_attempts_are_throttled(): void
    {
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->postJson('/host/login', ['pin' => 'no-es-el-pin'])->assertStatus(422);
        }

        $this->postJson('/host/login', ['pin' => 'pin-secreto'])->assertStatus(429);
    }
}
