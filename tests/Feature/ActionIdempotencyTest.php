<?php

namespace Tests\Feature;

use App\Models\AuctionAction;
use App\Services\ActionIdempotency;
use App\Support\ActionResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Guard de idempotencia de las acciones (4.7).
 *
 * Es el mismo que usan las pujas y las acciones del subastador: la clave es
 * `(actor_key, action_id)`.
 */
class ActionIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private ActionIdempotency $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guard = app(ActionIdempotency::class);
    }

    public function test_it_runs_the_action_once_and_returns_created(): void
    {
        $calls = 0;

        $response = $this->guard->run('host:abc', 'a1', function () use (&$calls): ActionResult {
            $calls++;

            return ActionResult::success(['value' => 42]);
        });

        $this->assertSame(1, $calls);
        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(42, $response->getData(true)['value']);
    }

    public function test_repeating_the_same_action_id_replays_without_rerunning(): void
    {
        $calls = 0;
        $action = function () use (&$calls): ActionResult {
            $calls++;

            return ActionResult::success(['value' => 1]);
        };

        $first = $this->guard->run('host:abc', 'a1', $action);
        $second = $this->guard->run('host:abc', 'a1', $action);

        $this->assertSame(1, $calls);
        $this->assertSame($first->getData(true), $second->getData(true));
        $this->assertSame(1, AuctionAction::query()->count());
    }

    public function test_a_rejection_is_stored_and_replayed(): void
    {
        $calls = 0;

        $response = $this->guard->run('host:abc', 'a1', function () use (&$calls): ActionResult {
            $calls++;

            return ActionResult::rejected('no_open_lot', 'No hay ningún lote abierto.');
        });

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('no_open_lot', $response->getData(true)['reason']);

        $this->guard->run('host:abc', 'a1', function () use (&$calls): ActionResult {
            $calls++;

            return ActionResult::success();
        });

        $this->assertSame(1, $calls);
    }

    public function test_the_same_action_id_under_another_actor_runs_again(): void
    {
        $calls = 0;
        $action = function () use (&$calls): ActionResult {
            $calls++;

            return ActionResult::success();
        };

        $this->guard->run('host:abc', 'a1', $action);
        $this->guard->run('participant:1', 'a1', $action);

        $this->assertSame(2, $calls);
    }

    public function test_an_unexpected_error_releases_the_claim(): void
    {
        try {
            $this->guard->run('host:abc', 'a1', function (): ActionResult {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
            // Esperado: el error no puede quedar como acción registrada.
        }

        $this->assertSame(0, AuctionAction::query()->count());
    }
}
