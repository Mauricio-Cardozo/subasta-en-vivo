<?php

namespace Tests\Feature;

use App\Enums\LotStatus;
use App\Models\AuctionAction;
use App\Models\AuctionRoom;
use App\Models\Lot;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `auction:close-expired`: la red de seguridad del cierre perezoso (4.4).
 */
class CloseExpiredLotsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function openLot(array $attributes): Lot
    {
        $room = AuctionRoom::factory()->create(['version' => 1]);
        $lot = Lot::factory()->for($room, 'room')->create($attributes);
        $room->update(['current_lot_id' => $lot->id]);

        return $lot;
    }

    public function test_it_settles_an_expired_lot_nobody_looked_at(): void
    {
        $lot = $this->openLot(['ends_at' => now()->subSecond()]);

        $this->artisan('auction:close-expired')
            ->expectsOutputToContain('versión 1 → 2')
            ->assertSuccessful();

        $this->assertSame(LotStatus::Unsold, $lot->fresh()->status);
        $this->assertSame('time', AuctionAction::query()->where('type', 'lot_closed')->sole()->payload['cause']);
    }

    public function test_it_leaves_a_live_lot_alone_and_is_idempotent(): void
    {
        $lot = $this->openLot(['ends_at' => now()->addMinute()]);

        $this->artisan('auction:close-expired')->expectsOutput('Nada para cerrar.')->assertSuccessful();
        $this->artisan('auction:close-expired')->expectsOutput('Nada para cerrar.')->assertSuccessful();

        $this->assertSame(LotStatus::Open, $lot->fresh()->status);
        $this->assertSame(1, $lot->room->fresh()->version);
    }

    public function test_it_runs_every_minute(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'auction:close-expired'));

        $this->assertNotNull($event, 'El comando no está programado.');
        $this->assertSame('* * * * *', $event->expression);
    }
}
