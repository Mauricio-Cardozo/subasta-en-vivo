<?php

namespace Tests\Feature;

use App\Events\AuctionStateUpdated;
use App\Http\Controllers\Dev\BroadcastController;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DevBroadcastTest extends TestCase
{
    public function test_the_dev_broadcast_route_is_not_registered_outside_local(): void
    {
        $this->assertFalse(
            Route::has('dev.broadcast'),
            'El emisor de prueba no debe existir fuera del entorno local.'
        );
    }

    public function test_the_controller_dispatches_a_confirmed_state_update(): void
    {
        Event::fake([AuctionStateUpdated::class]);

        $controller = new BroadcastController;

        $response = $controller(request()->merge(['version' => 7, 'summary' => 'Lote de prueba']));
        $payload = $response->getData(true);

        $this->assertTrue($payload['dispatched']);
        $this->assertSame(7, $payload['version']);

        Event::assertDispatched(
            AuctionStateUpdated::class,
            function (AuctionStateUpdated $event): bool {
                $snapshot = $event->broadcastWith();

                return $snapshot['version'] === 7
                    && $snapshot['action']['type'] === 'dev_ping'
                    && $snapshot['action']['summary'] === 'Lote de prueba'
                    && $snapshot['lot'] === null
                    && $snapshot['bids'] === [];
            }
        );
    }

    public function test_the_dev_summary_is_validated(): void
    {
        Event::fake([AuctionStateUpdated::class]);

        $controller = new BroadcastController;

        $this->expectException(ValidationException::class);

        $controller(request()->merge(['summary' => str_repeat('x', 81)]));
    }
}
