<?php

namespace Tests\Feature;

use App\Enums\LotStatus;
use App\Models\AuctionAction;
use App\Models\AuctionRoom;
use App\Models\ChatMessage;
use App\Models\Lot;
use App\Models\Participant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tarea 1: un fallo de difusión nunca rompe la acción confirmada.
 *
 * El broadcaster real (Reverb/Pusher) apunta a un puerto muerto, así que cada
 * evento lanza `BroadcastException` al salir a la red. La acción tiene que
 * responder normal, la base quedar confirmada, el `action_id` completado y el
 * fallo registrado en el canal `auction` con el `correlation_id`.
 */
class BroadcastFailureTest extends TestCase
{
    use RefreshDatabase;

    private string $logDir;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('auction.host_pin', 'pin-secreto');

        // Reverb caído de verdad: el puerto 1 rechaza la conexión al instante.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
            'broadcasting.connections.reverb.options' => [
                'host' => '127.0.0.1',
                'port' => 1,
                'scheme' => 'http',
                'useTLS' => false,
            ],
        ]);

        $this->logDir = sys_get_temp_dir().'/auction-broadcast-'.uniqid();
        mkdir($this->logDir);
        config()->set('logging.channels.auction.handler_with.filename', $this->logDir.'/auction.log');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->logDir)) {
            foreach (glob($this->logDir.'/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($this->logDir);
        }

        parent::tearDown();
    }

    private function asHost(): void
    {
        $this->postJson('/host/login', ['pin' => 'pin-secreto'])->assertOk();
    }

    private function join(string $name): void
    {
        $this->postJson('/join', ['name' => $name])->assertCreated();
    }

    /**
     * Registros `broadcast.failed` del canal `auction` (archivo rotado a diario).
     *
     * @return list<array<string, mixed>>
     */
    private function broadcastFailures(): array
    {
        $records = [];

        foreach (glob($this->logDir.'/auction-*.log') ?: [] as $file) {
            foreach (explode("\n", trim((string) file_get_contents($file))) as $line) {
                if ($line === '') {
                    continue;
                }

                $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

                if (($record['message'] ?? null) === 'broadcast.failed') {
                    $records[] = $record;
                }
            }
        }

        return $records;
    }

    /**
     * @param  list<string>  $correlationIds
     * @return list<array<string, mixed>>
     */
    private function assertFailuresLogged(array $correlationIds): array
    {
        $failures = $this->broadcastFailures();

        $this->assertCount(count($correlationIds), $failures, 'Se esperaba una línea por cada fallo de difusión.');

        foreach ($correlationIds as $correlationId) {
            $matching = array_filter(
                $failures,
                fn (array $record): bool => ($record['context']['correlation_id'] ?? null) === $correlationId,
            );

            $this->assertCount(1, $matching, "Falta la línea del fallo con correlation_id «{$correlationId}».");
        }

        foreach ($failures as $failure) {
            $this->assertSame('ERROR', $failure['level_name']);
            $this->assertSame(['auction'], $failure['context']['channels']);
            $this->assertNotEmpty($failure['context']['exception']);
        }

        return $failures;
    }

    public function test_opening_a_lot_survives_a_broadcast_failure(): void
    {
        AuctionRoom::factory()->create();
        $this->asHost();

        $this->withHeader('X-Correlation-ID', 'cid-open-1')
            ->postJson('/auction/lots', [
                'title' => 'Reloj antiguo',
                'description' => 'Funciona y anda bien',
                'start_price_cents' => 5_000,
                'duration_seconds' => 60,
            ])
            ->assertCreated()
            ->assertHeader('X-Correlation-ID', 'cid-open-1')
            ->assertJson(['version' => 1]);

        $lot = Lot::query()->sole();
        $this->assertSame(LotStatus::Open, $lot->status);
        $this->assertSame(1, AuctionRoom::query()->sole()->version);

        $failures = $this->assertFailuresLogged(['cid-open-1']);
        $this->assertSame('AuctionStateUpdated', $failures[0]['context']['event']);
    }

    public function test_a_bid_survives_a_broadcast_failure_and_keeps_the_action_completed(): void
    {
        $room = AuctionRoom::factory()->create();
        $lot = Lot::factory()->for($room, 'room')->create([
            'start_price_cents' => 5_000,
            'min_increment_cents' => 100,
        ]);
        $room->update(['current_lot_id' => $lot->id]);

        $this->join('Ana');

        $this->withHeader('X-Correlation-ID', 'cid-bid-1')
            ->postJson('/auction/bids', ['action_id' => 'bid-1'])
            ->assertCreated()
            ->assertJson(['amount_cents' => 5_000]);

        $fresh = $lot->fresh();
        $this->assertSame(5_000, $fresh->current_price_cents);
        $this->assertNotNull($fresh->leader_participant_id);
        $this->assertSame(1, $fresh->room->version);

        $action = AuctionAction::query()->where('action_id', 'bid-1')->sole();
        $this->assertIsArray($action->result, 'La acción tiene que quedar completada, no liberada.');
        $this->assertSame(201, $action->result['status']);

        $failures = $this->assertFailuresLogged(['cid-bid-1']);
        $this->assertSame('AuctionStateUpdated', $failures[0]['context']['event']);
    }

    public function test_closing_a_lot_survives_a_broadcast_failure_and_keeps_the_action_completed(): void
    {
        $room = AuctionRoom::factory()->create();
        $lot = Lot::factory()->for($room, 'room')->create(['title' => 'Reloj']);
        $ana = Participant::factory()->create(['name' => 'Ana', 'balance_cents' => 100_000]);
        $lot->update(['current_price_cents' => 1_000, 'leader_participant_id' => $ana->id]);
        $room->update(['current_lot_id' => $lot->id, 'version' => 1]);

        $this->asHost();

        $this->withHeader('X-Correlation-ID', 'cid-close-1')
            ->postJson('/auction/lots/close', ['action_id' => 'close-1'])
            ->assertCreated()
            ->assertJson(['status' => 'sold', 'winner_name' => 'Ana']);

        $this->assertSame(LotStatus::Sold, $lot->fresh()->status);
        $this->assertSame(99_000, $ana->fresh()->balance_cents);

        $action = AuctionAction::query()->where('action_id', 'close-1')->sole();
        $this->assertIsArray($action->result, 'La acción tiene que quedar completada, no liberada.');
        $this->assertSame(201, $action->result['status']);

        $failures = $this->assertFailuresLogged(['cid-close-1']);
        $this->assertSame('AuctionStateUpdated', $failures[0]['context']['event']);
    }

    public function test_chat_post_and_delete_survive_a_broadcast_failure(): void
    {
        AuctionRoom::factory()->create();
        $this->join('Ana');

        $messageId = $this->withHeader('X-Correlation-ID', 'cid-chat-1')
            ->postJson('/auction/chat', ['body' => 'Hola a todos'])
            ->assertCreated()
            ->json('id');

        $this->withHeader('X-Correlation-ID', 'cid-chat-2')
            ->deleteJson("/auction/chat/{$messageId}")
            ->assertNoContent();

        $this->assertSame(0, ChatMessage::query()->count());

        $failures = $this->assertFailuresLogged(['cid-chat-1', 'cid-chat-2']);

        $this->assertSame(
            ['ChatMessagePosted', 'ChatMessageDeleted'],
            array_column(array_column($failures, 'context'), 'event'),
        );
    }
}
