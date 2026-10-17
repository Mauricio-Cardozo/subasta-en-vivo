<?php

namespace Tests\Feature;

use App\Models\AuctionRoom;
use App\Models\Lot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Auditoría de acciones (trazabilidad).
 *
 * Cada acción deja un registro estructurado en el canal `audit`, con el
 * `correlation_id` de su petición. El canal se redirige a un archivo temporal
 * para leer los registros.
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('auction.host_pin', 'pin-secreto');
    }

    /**
     * Ejecuta la acción con el canal `audit` apuntando a un archivo temporal y
     * devuelve los registros JSON.
     *
     * @return list<array<string, mixed>>
     */
    private function audit(callable $action): array
    {
        $dir = sys_get_temp_dir().'/auction-audit-'.uniqid();
        mkdir($dir);
        config()->set('logging.channels.audit.handler_with.filename', $dir.'/audit.log');

        $action();

        $records = [];

        foreach (glob($dir.'/audit-*.log') ?: [] as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $records[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            }
        }

        foreach (glob($dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        rmdir($dir);

        return $records;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return list<array<string, mixed>>
     */
    private function events(array $records, string $event): array
    {
        return array_values(array_filter(
            $records,
            fn (array $record): bool => $record['message'] === $event,
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array<string, mixed>
     */
    private function event(array $records, string $event): array
    {
        $matches = $this->events($records, $event);

        $this->assertNotEmpty($matches, "No se auditó el evento {$event}.");

        return $matches[0];
    }

    private function join(string $name): void
    {
        $this->postJson('/join', ['name' => $name])->assertCreated();
    }

    private function asHost(): void
    {
        $this->postJson('/host/login', ['pin' => 'pin-secreto'])->assertOk();
    }

    private function roomWithLot(): Lot
    {
        $room = AuctionRoom::factory()->create();
        $lot = Lot::factory()->for($room, 'room')->create([
            'start_price_cents' => 5_000,
            'min_increment_cents' => 100,
        ]);
        $room->update(['current_lot_id' => $lot->id]);

        return $lot;
    }

    public function test_a_join_is_audited(): void
    {
        $records = $this->audit(fn () => $this->join('Ana'));

        $entry = $this->event($records, 'identity.joined');

        $this->assertStringStartsWith('participant:', $entry['context']['actor_key']);
        $this->assertSame('Ana', $entry['context']['name']);
        $this->assertSame('127.0.0.1', $entry['context']['ip']);
    }

    public function test_a_host_login_is_audited(): void
    {
        $records = $this->audit(fn () => $this->asHost());

        $entry = $this->event($records, 'host.login');

        $this->assertSame('accepted', $entry['context']['outcome']);
        $this->assertStringStartsWith('host:', $entry['context']['actor_key']);
    }

    public function test_a_failed_login_is_audited_without_the_pin(): void
    {
        $records = $this->audit(
            fn () => $this->postJson('/host/login', ['pin' => 'pin-incorrecto'])->assertStatus(422),
        );

        $entry = $this->event($records, 'host.login_failed');

        $this->assertSame('rejected', $entry['context']['outcome']);
        $this->assertStringNotContainsString('pin-incorrecto', json_encode($records, JSON_THROW_ON_ERROR));
    }

    public function test_a_bid_is_audited_accepted_rejected_and_replayed(): void
    {
        $this->roomWithLot();

        $records = $this->audit(function (): void {
            $this->join('Ana');
            $this->postJson('/auction/bids', ['action_id' => 'a1'])->assertCreated();
            $this->postJson('/auction/bids', ['action_id' => 'a2'])->assertStatus(422); // ya lidera
            $this->postJson('/auction/bids', ['action_id' => 'a1'])->assertCreated(); // repetida
        });

        $bids = $this->events($records, 'auction.bid');

        $this->assertSame(['accepted', 'rejected', 'replayed'], array_column(
            array_map(fn (array $record): array => $record['context'], $bids),
            'outcome',
        ));
        $this->assertSame('already_leading', $bids[1]['context']['reason']);
    }

    public function test_opening_and_closing_a_lot_are_audited(): void
    {
        AuctionRoom::factory()->create();

        $records = $this->audit(function (): void {
            $this->asHost();
            $this->postJson('/auction/lots', [
                'title' => 'Reloj antiguo',
                'start_price_cents' => 5_000,
                'action_id' => 'apertura-1',
            ])->assertCreated();
            $this->postJson('/auction/lots/close', ['action_id' => 'cierre-1'])->assertCreated();
        });

        $opened = $this->event($records, 'auction.lot_open');

        $this->assertSame('accepted', $opened['context']['outcome']);
        $this->assertSame('open', $opened['context']['result']['status']);
        $this->assertSame('accepted', $this->event($records, 'auction.lot_close')['context']['outcome']);
    }

    public function test_queuing_a_lot_and_removing_it_from_the_queue_are_audited(): void
    {
        AuctionRoom::factory()->create();

        $records = $this->audit(function (): void {
            $this->asHost();
            $this->postJson('/auction/lots', [
                'title' => 'Reloj antiguo',
                'start_price_cents' => 5_000,
                'action_id' => 'apertura-1',
            ])->assertCreated();

            $queuedId = $this->postJson('/auction/lots', [
                'title' => 'Reloj de pie',
                'start_price_cents' => 7_000,
                'action_id' => 'apertura-2',
            ])->assertCreated()->json('lot_id');

            $this->deleteJson("/auction/lots/{$queuedId}")->assertOk();
            // La repetición no vuelve a transicionar: se audita como `replayed`.
            $this->deleteJson("/auction/lots/{$queuedId}")->assertOk();
        });

        // Las dos aperturas comparten evento: el resultado dice cuál abrió y
        // cuál quedó esperando.
        $opens = $this->events($records, 'auction.lot_open');

        $this->assertSame(['open', 'queued'], array_column(
            array_map(fn (array $record): array => $record['context']['result'], $opens),
            'status',
        ));

        $removals = $this->events($records, 'auction.lot_dequeued');

        $this->assertSame(['accepted', 'replayed'], array_column(
            array_map(fn (array $record): array => $record['context'], $removals),
            'outcome',
        ));
        $this->assertSame('cancelled', $removals[0]['context']['result']['status']);
    }

    public function test_chat_post_delete_and_denial_are_audited(): void
    {
        $records = $this->audit(function (): void {
            $this->join('Ana');
            $messageId = $this->postJson('/auction/chat', ['body' => 'Hola'])->assertCreated()->json('id');
            $this->deleteJson("/auction/chat/{$messageId}")->assertNoContent();

            // Otro pujador no puede borrar un mensaje ajeno.
            $this->join('Beto');
            $otherId = $this->postJson('/auction/chat', ['body' => 'Mío'])->assertCreated()->json('id');
            $this->join('Caro');
            $this->deleteJson("/auction/chat/{$otherId}")->assertForbidden();
        });

        $this->assertSame('accepted', $this->event($records, 'chat.posted')['context']['outcome']);
        $this->assertSame('accepted', $this->event($records, 'chat.deleted')['context']['outcome']);

        $denied = $this->event($records, 'chat.delete_denied');
        $this->assertSame('rejected', $denied['context']['outcome']);
        $this->assertSame('forbidden', $denied['context']['reason']);
    }

    public function test_the_audit_entry_carries_the_correlation_id(): void
    {
        $records = $this->audit(
            fn () => $this->withHeader('X-Correlation-ID', 'cid-audit')->postJson('/join', ['name' => 'Ana']),
        );

        $this->assertSame('cid-audit', $this->event($records, 'identity.joined')['context']['correlation_id']);
    }
}
