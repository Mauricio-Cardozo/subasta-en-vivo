<?php

namespace Tests\Feature;

use App\Models\AuctionRoom;
use App\Models\Bid;
use App\Models\Lot;
use App\Models\Participant;
use App\Services\AdminDashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Panel `/admin` (RF-18): solo el subastador, solo lectura.
 */
class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    private string $logDir;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('auction.host_pin', 'pin-secreto');

        $this->logDir = sys_get_temp_dir().'/auction-admin-'.uniqid();
        mkdir($this->logDir);
        config()->set('logging.channels.sql.handler_with.filename', $this->logDir.'/sql.log');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->logDir.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->logDir);

        parent::tearDown();
    }

    public function test_only_the_host_sees_the_panel(): void
    {
        $this->get('/admin')->assertForbidden();

        $this->postJson('/join', ['name' => 'Ana'])->assertCreated();
        $this->get('/admin')->assertForbidden();

        $this->postJson('/host/login', ['pin' => 'pin-secreto'])->assertOk();
        $this->get('/admin')
            ->assertOk()
            ->assertSee('Pujas por minuto')
            ->assertSee('Últimas acciones')
            ->assertSee('Consultas lentas');
    }

    public function test_bids_are_counted_per_minute_including_empty_minutes(): void
    {
        $this->freezeTime();
        $room = AuctionRoom::factory()->create();
        $lot = Lot::factory()->for($room, 'room')->create();
        $ana = Participant::factory()->create();

        Bid::factory()->count(3)->for($lot)->for($ana)->create(['created_at' => now()]);
        Bid::factory()->for($lot)->for($ana)->create(['created_at' => now()->subMinutes(2)]);
        // Fuera de la ventana.
        Bid::factory()->for($lot)->for($ana)->create(['created_at' => now()->subMinutes(30)]);

        $series = app(AdminDashboard::class)->bidsPerMinute(5);

        $this->assertSame([0, 0, 1, 0, 3], array_column($series, 'count'));
        $this->assertSame(now()->format('H:i'), end($series)['minute']);
    }

    public function test_the_panel_lists_audited_actions_and_slow_queries(): void
    {
        $this->postJson('/host/login', ['pin' => 'pin-secreto'])->assertOk();
        AuctionRoom::factory()->create();
        $this->postJson('/auction/lots', ['title' => 'Jarrón', 'start_price_cents' => 1_000])->assertCreated();

        Log::channel('sql')->warning('sql.slow', ['sql' => 'select * from "bids" where "lot_id" = ?', 'ms' => 72.5]);

        $this->get('/admin')
            ->assertOk()
            ->assertSee('open_lot')
            ->assertSee('aceptada')
            ->assertSee('72.5 ms')
            ->assertSee('select * from &quot;bids&quot; where &quot;lot_id&quot; = ?', escape: false);
    }
}
