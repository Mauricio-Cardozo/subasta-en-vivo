<?php

namespace Tests\Feature;

use App\Events\AuctionStateUpdated;
use App\Models\AuctionRoom;
use App\Models\Lot;
use App\Models\Participant;
use App\Services\AuctionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Anti-sniping (4.3): una puja en la ventana final corre el cierre, con un tope
 * de extensiones. Los valores salen de config/auction.php.
 */
class AntiSnipingTest extends TestCase
{
    use RefreshDatabase;

    private AuctionService $auction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->auction = app(AuctionService::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function openLot(array $attributes = []): Lot
    {
        $room = AuctionRoom::factory()->create();
        $lot = Lot::factory()->for($room, 'room')->create($attributes);
        $room->update(['current_lot_id' => $lot->id, 'version' => 1]);

        return $lot->fresh();
    }

    public function test_a_bid_inside_the_final_window_extends_the_close(): void
    {
        $lot = $this->openLot(['ends_at' => now()->addSeconds(3)]);
        $closesAt = $lot->ends_at->getTimestamp();

        $result = $this->auction->placeBid(Participant::factory()->create());

        $this->assertTrue($result->ok);
        $this->assertSame(
            $closesAt + config('auction.anti_sniping.extension_seconds'),
            $lot->fresh()->ends_at->getTimestamp()
        );
        $this->assertSame(1, $lot->fresh()->extensions);
        $this->assertTrue($result->context['extended']);
    }

    public function test_a_bid_outside_the_window_does_not_extend(): void
    {
        $lot = $this->openLot(['ends_at' => now()->addSeconds(60)]);
        $closesAt = $lot->ends_at->getTimestamp();

        $result = $this->auction->placeBid(Participant::factory()->create());

        $this->assertTrue($result->ok);
        $this->assertSame($closesAt, $lot->fresh()->ends_at->getTimestamp());
        $this->assertSame(0, $lot->fresh()->extensions);
        $this->assertFalse($result->context['extended']);
    }

    public function test_the_extensions_are_capped(): void
    {
        $max = config('auction.anti_sniping.max_extensions');
        $lot = $this->openLot(['ends_at' => now()->addSeconds(3), 'extensions' => $max]);
        $closesAt = $lot->ends_at->getTimestamp();

        $result = $this->auction->placeBid(Participant::factory()->create());

        $this->assertTrue($result->ok);
        $this->assertSame($closesAt, $lot->fresh()->ends_at->getTimestamp());
        $this->assertSame($max, $lot->fresh()->extensions);
        $this->assertFalse($result->context['extended']);
    }

    public function test_the_bid_and_the_extension_are_one_transition(): void
    {
        $lot = $this->openLot(['ends_at' => now()->addSeconds(3)]);

        $this->auction->placeBid(Participant::factory()->create());

        // Una sola subida de versión para la puja y la extensión juntas.
        $this->assertSame(2, $lot->fresh()->room->version);
    }

    public function test_the_event_announces_the_extension(): void
    {
        $lot = $this->openLot(['ends_at' => now()->addSeconds(3), 'start_price_cents' => 5_000]);
        $ana = Participant::factory()->create(['name' => 'Ana']);

        Event::fake([AuctionStateUpdated::class]);

        $this->auction->placeBid($ana);

        Event::assertDispatched(AuctionStateUpdated::class, function (AuctionStateUpdated $event): bool {
            $payload = $event->broadcastWith();

            return $payload['action']['summary'] === 'Ana pujó $ 50,00 y se extendió el cierre (1/'
                    .config('auction.anti_sniping.max_extensions').')'
                && $payload['lot']['extensions'] === 1
                && $payload['lot']['remaining_ms'] > config('auction.anti_sniping.extension_seconds') * 1000 - 1_500;
        });
    }

    public function test_the_window_and_extension_come_from_config(): void
    {
        config()->set('auction.anti_sniping.window_seconds', 30);
        config()->set('auction.anti_sniping.extension_seconds', 45);

        $lot = $this->openLot(['ends_at' => now()->addSeconds(20)]);
        $closesAt = $lot->ends_at->getTimestamp();

        $this->auction->placeBid(Participant::factory()->create());

        $this->assertSame($closesAt + 45, $lot->fresh()->ends_at->getTimestamp());
    }
}
