<?php

namespace Tests\Feature;

use App\Enums\LotStatus;
use App\Models\AuctionAction;
use App\Models\AuctionRoom;
use App\Models\Bid;
use App\Models\ChatMessage;
use App\Models\Lot;
use App\Models\Participant;
use App\Services\AuctionService;
use App\Services\ChatService;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Concurrencia real (RNF-02).
 *
 * `lockForUpdate` no hace nada en SQLite, así que estos tests solo corren
 * contra un motor con bloqueo de fila (PostgreSQL o MySQL). No usan
 * transacciones envolventes: los procesos hijos son conexiones distintas y
 * necesitan ver los datos confirmados.
 */
class ConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql'], true)) {
            $this->markTestSkipped('La concurrencia real necesita PostgreSQL o MySQL.');
        }

        $this->shareDatabaseWithChildProcesses();
    }

    /**
     * Los procesos hijos arrancan la aplicación de cero: heredan la conexión
     * a la base por variables de entorno.
     */
    private function shareDatabaseWithChildProcesses(): void
    {
        $name = config('database.default');
        $connection = config("database.connections.{$name}");

        $values = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => $name,
            'DB_HOST' => $connection['host'] ?? '127.0.0.1',
            'DB_PORT' => (string) ($connection['port'] ?? '5432'),
            'DB_DATABASE' => $connection['database'] ?? '',
            'DB_USERNAME' => $connection['username'] ?? '',
            'DB_PASSWORD' => $connection['password'] ?? '',
            'BROADCAST_CONNECTION' => 'null',
            'QUEUE_CONNECTION' => 'sync',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
        ];

        foreach ($values as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function openLot(array $attributes = []): Lot
    {
        $room = AuctionRoom::factory()->create();
        $lot = Lot::factory()->for($room, 'room')->create($attributes);
        $room->update(['current_lot_id' => $lot->id]);

        return $lot->fresh();
    }

    /**
     * Tarea que corre en un proceso aparte: puja y devuelve un resultado plano.
     */
    private function bidTask(int $participantId, ?int $amountCents): Closure
    {
        return function () use ($participantId, $amountCents): array {
            $participant = Participant::query()->findOrFail($participantId);
            $result = app(AuctionService::class)->placeBid($participant, $amountCents);

            return [
                'ok' => $result->ok,
                'reason' => $result->reason,
                'amount_cents' => $result->context['amount_cents'] ?? null,
                'extended' => $result->context['extended'] ?? null,
                'ends_at' => $result->context['ends_at'] ?? null,
            ];
        };
    }

    /**
     * Tarea que cierra el lote abierto desde otro proceso (cierre manual).
     */
    private function closeTask(): Closure
    {
        return function (): array {
            $result = app(AuctionService::class)->closeCurrentLot();

            return [
                'ok' => $result->ok,
                'reason' => $result->reason,
                'status' => $result->context['status'] ?? null,
                'winner_name' => $result->context['winner_name'] ?? null,
                'price_cents' => $result->context['price_cents'] ?? null,
            ];
        };
    }

    private function claimTask(string $actorKey, string $actionId): Closure
    {
        return function () use ($actorKey, $actionId): array {
            try {
                AuctionAction::query()->create([
                    'actor_key' => $actorKey,
                    'action_id' => $actionId,
                    'result' => ['ok' => true],
                ]);

                return ['ok' => true];
            } catch (QueryException) {
                return ['ok' => false];
            }
        };
    }

    /**
     * Tarea que publica un mensaje de chat desde otro proceso.
     */
    private function postMessageTask(int $participantId, string $body): Closure
    {
        return function () use ($participantId, $body): array {
            $participant = Participant::query()->findOrFail($participantId);
            $message = app(ChatService::class)->post($participant, $participant->name, $body);

            return ['id' => $message->id];
        };
    }

    /**
     * Tarea que borra un mensaje (moderación) desde otro proceso.
     *
     * Tolerante a que el mensaje ya no esté: dos borrados del mismo id no deben
     * romper (el segundo ve la fila ausente y no hay efecto que duplicar).
     */
    private function deleteMessageTask(int $messageId): Closure
    {
        return function () use ($messageId): array {
            $message = ChatMessage::query()->find($messageId);

            if ($message === null) {
                return ['ok' => false, 'reason' => 'not_found'];
            }

            app(ChatService::class)->delete($message);

            return ['ok' => true];
        };
    }

    public function test_the_row_lock_serialises_two_connections(): void
    {
        $room = AuctionRoom::factory()->create();

        config(['database.connections.second' => config('database.connections.'.config('database.default'))]);

        $first = DB::connection();
        $second = DB::connection('second');

        $first->beginTransaction();
        $first->table('auction_rooms')->where('id', $room->id)->lockForUpdate()->first();

        $second->beginTransaction();
        $this->setShortLockTimeout($second);

        try {
            $second->table('auction_rooms')->where('id', $room->id)->lockForUpdate()->first();
            $second->rollBack();
            $this->fail('La segunda conexión consiguió el bloqueo con la primera todavía activa.');
        } catch (QueryException) {
            // Esperado: la fila está bloqueada y la espera se agotó.
            $second->rollBack();
        }

        $first->commit();

        // Liberado el bloqueo, la segunda conexión entra sin problema.
        $second->beginTransaction();
        $second->table('auction_rooms')->where('id', $room->id)->lockForUpdate()->first();
        $second->commit();

        $this->assertTrue(true);
    }

    public function test_concurrent_bids_do_not_lose_an_update(): void
    {
        $lot = $this->openLot(['start_price_cents' => 5_000, 'min_increment_cents' => 100]);

        $bidders = Participant::factory()->count(4)->create();

        $results = array_values(Concurrency::run(
            $bidders->map(fn (Participant $participant) => $this->bidTask($participant->id, null))->all()
        ));

        $this->assertSame([true, true, true, true], array_column($results, 'ok'));

        // Cuatro pujas confirmadas: cuatro incrementos y cuatro versiones.
        $this->assertSame(4, Bid::query()->count());
        $this->assertSame(4, $lot->fresh()->room->version);

        // Sin el bloqueo, dos procesos leerían el mismo precio y una puja
        // pisaría a la otra: los montos se repetirían.
        $amounts = Bid::query()->orderBy('amount_cents')->pluck('amount_cents')->all();
        $this->assertSame([5_000, 5_100, 5_200, 5_300], $amounts);
        $this->assertSame(5_300, $lot->fresh()->current_price_cents);
    }

    public function test_two_bids_of_the_same_amount_leave_a_single_winner(): void
    {
        $lot = $this->openLot(['start_price_cents' => 5_000, 'min_increment_cents' => 100]);

        [$ana, $beto] = Participant::factory()->count(2)->create();

        $results = array_values(Concurrency::run([
            $this->bidTask($ana->id, 5_000),
            $this->bidTask($beto->id, 5_000),
        ]));

        $winners = array_filter($results, fn (array $result): bool => $result['ok']);
        $this->assertCount(1, $winners, 'Dos pujas del mismo monto no pueden ganar las dos.');

        $loser = array_values(array_filter($results, fn (array $result): bool => ! $result['ok']))[0];
        $this->assertSame('bid_too_low', $loser['reason']);

        $this->assertSame(1, Bid::query()->count());
        $this->assertSame(1, $lot->fresh()->room->version);
        $this->assertSame(5_000, $lot->fresh()->current_price_cents);
    }

    public function test_two_concurrent_closes_settle_the_lot_once(): void
    {
        $lot = $this->openLot(['start_price_cents' => 5_000, 'min_increment_cents' => 100]);
        $leader = Participant::factory()->create(['balance_cents' => 100_000]);

        // Una puja confirmada deja precio y ganador antes de la carrera de cierres.
        app(AuctionService::class)->placeBid($leader, null);

        $results = array_values(Concurrency::run([
            $this->closeTask(),
            $this->closeTask(),
        ]));

        $winners = array_filter($results, fn (array $result): bool => $result['ok']);
        $this->assertCount(1, $winners, 'Dos cierres no pueden adjudicar el lote los dos.');

        $loser = array_values(array_filter($results, fn (array $result): bool => ! $result['ok']))[0];
        $this->assertSame('no_open_lot', $loser['reason']);

        $lot->refresh();
        $this->assertSame(LotStatus::Sold, $lot->status);
        $this->assertSame($leader->id, $lot->winner_participant_id);

        // El saldo se descuenta exactamente una vez.
        $this->assertSame(100_000 - 5_000, $leader->fresh()->balance_cents);

        // Una versión por la puja y una sola por el cierre.
        $this->assertSame(2, $lot->room->version);
    }

    public function test_a_close_and_a_bid_leave_a_consistent_lot(): void
    {
        $lot = $this->openLot(['start_price_cents' => 5_000, 'min_increment_cents' => 100]);
        $bidder = Participant::factory()->create(['balance_cents' => 100_000]);

        $results = Concurrency::run([
            'close' => $this->closeTask(),
            'bid' => $this->bidTask($bidder->id, null),
        ]);

        $bid = $results['bid'];
        $lot->refresh();

        // El cierre siempre encuentra el lote abierto: es el único que lo cierra.
        $this->assertTrue($results['close']['ok'], 'El cierre no debería fallar nunca.');

        if ($bid['ok']) {
            // La puja entró y el cierre la adjudicó.
            $this->assertSame(LotStatus::Sold, $lot->status);
            $this->assertSame($bidder->id, $lot->winner_participant_id);
            $this->assertSame(5_000, $lot->current_price_cents);
            $this->assertSame(100_000 - 5_000, $bidder->fresh()->balance_cents);
            $this->assertSame(2, $lot->room->version);
        } else {
            // El cierre ganó la carrera: sin pujas, queda sin vender.
            $this->assertContains($bid['reason'], ['lot_closed', 'lot_expired', 'no_open_lot']);
            $this->assertSame(LotStatus::Unsold, $lot->status);
            $this->assertNull($lot->winner_participant_id);
            $this->assertSame(100_000, $bidder->fresh()->balance_cents);
            $this->assertSame(1, $lot->room->version);
        }

        $this->assertSame($bid['ok'] ? 1 : 0, Bid::query()->count());
    }

    public function test_edge_bids_extend_the_close_within_the_cap(): void
    {
        $window = (int) config('auction.anti_sniping.window_seconds');
        $extension = (int) config('auction.anti_sniping.extension_seconds');
        $maxExtensions = (int) config('auction.anti_sniping.max_extensions');

        $lot = $this->openLot(['start_price_cents' => 5_000, 'min_increment_cents' => 100]);

        // El cierre cae dentro de la ventana final, pero todavía no venció.
        $originalEndsAt = now()->startOfSecond()->addSeconds($window - 1);
        $lot->forceFill(['ends_at' => $originalEndsAt])->save();

        $bidders = Participant::factory()->count(3)->create();

        $results = array_values(Concurrency::run(
            $bidders->map(fn (Participant $participant) => $this->bidTask($participant->id, null))->all()
        ));

        $accepted = array_filter($results, fn (array $result): bool => $result['ok']);
        $this->assertCount(3, $accepted, 'Las pujas dentro de la ventana deben aceptarse.');

        $lot->refresh();

        // Cada ronda deja al menos una extensión, nunca más que las pujas ni el tope.
        $this->assertGreaterThanOrEqual(1, $lot->extensions);
        $this->assertLessThanOrEqual(min($maxExtensions, count($accepted)), $lot->extensions);
        $this->assertLessThanOrEqual(
            $maxExtensions,
            $lot->extensions,
            'El anti-sniping no puede pasar su tope.'
        );

        // El cierre corrido es exactamente el original más las extensiones aplicadas.
        $this->assertTrue(
            $originalEndsAt->copy()->addSeconds($lot->extensions * $extension)->equalTo($lot->ends_at),
            'El cierre no coincide con las extensiones registradas.'
        );

        // Ninguna puja ni versión se pierde.
        $this->assertSame(3, Bid::query()->count());
        $this->assertSame(3, $lot->room->version);
        $this->assertSame(5_000 + 2 * 100, $lot->current_price_cents);
    }

    public function test_a_concurrent_duplicate_action_is_recorded_once(): void
    {
        $results = array_values(Concurrency::run([
            $this->claimTask('participant:1', 'la-misma-accion'),
            $this->claimTask('participant:1', 'la-misma-accion'),
        ]));

        $this->assertSame(1, count(array_filter($results, fn (array $result): bool => $result['ok'])));
        $this->assertSame(1, AuctionAction::query()->count());
    }

    public function test_concurrent_messages_are_all_kept_and_ordered_by_id(): void
    {
        AuctionRoom::factory()->create();

        $senders = Participant::factory()->count(5)->create();

        $results = array_values(Concurrency::run(
            $senders->map(
                fn (Participant $participant, int $index) => $this->postMessageTask($participant->id, "Mensaje {$index}")
            )->all()
        ));

        // Nadie se pierde: cinco publicaciones, cinco filas.
        $this->assertSame(5, ChatMessage::query()->count());
        $this->assertCount(5, array_filter($results, fn (array $result): bool => isset($result['id'])));

        // El historial sale en orden estable por `id`, sin importar el orden en
        // que los procesos confirmaron (el chat no usa `version`).
        $ids = array_column(app(ChatService::class)->history(), 'id');

        $this->assertSame(array_values(array_unique($ids)), $ids, 'No puede repetirse un id.');
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids, 'El historial debe venir ordenado por id.');
    }

    public function test_two_concurrent_deletes_of_the_same_message_remove_it_once(): void
    {
        $room = AuctionRoom::factory()->create();
        $author = Participant::factory()->create();
        $message = ChatMessage::factory()->create([
            'room_id' => $room->id,
            'participant_id' => $author->id,
            'author_name' => $author->name,
        ]);

        $results = array_values(Concurrency::run([
            $this->deleteMessageTask($message->id),
            $this->deleteMessageTask($message->id),
        ]));

        // Ninguno rompe y el efecto es único: la fila no queda (repita o no la
        // operación, el resultado es el mismo).
        $this->assertCount(2, $results);
        $this->assertGreaterThanOrEqual(1, count(array_filter($results, fn (array $result): bool => $result['ok'])));
        $this->assertSame(0, ChatMessage::query()->count());
    }

    public function test_a_host_delete_and_a_concurrent_post_leave_a_consistent_history(): void
    {
        $room = AuctionRoom::factory()->create();
        $author = Participant::factory()->create();
        $target = ChatMessage::factory()->create([
            'room_id' => $room->id,
            'participant_id' => $author->id,
            'author_name' => $author->name,
            'body' => 'Fuera de tono',
        ]);

        $results = Concurrency::run([
            'delete' => $this->deleteMessageTask($target->id),
            'post' => $this->postMessageTask($author->id, 'Mensaje nuevo'),
        ]);

        $this->assertTrue($results['delete']['ok']);
        $this->assertNotNull($results['post']['id']);

        // El borrado no se lleva el mensaje nuevo: queda uno solo, y el
        // historial sale coherente y ordenado.
        $this->assertSame(1, ChatMessage::query()->count());

        $history = app(ChatService::class)->history();
        $bodies = array_column($history, 'body');

        $this->assertContains('Mensaje nuevo', $bodies);
        $this->assertNotContains('Fuera de tono', $bodies);

        $ids = array_column($history, 'id');
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids);
    }

    private function setShortLockTimeout(Connection $connection): void
    {
        if ($connection->getDriverName() === 'pgsql') {
            $connection->statement("SET LOCAL lock_timeout = '300ms'");

            return;
        }

        $connection->statement('SET SESSION innodb_lock_wait_timeout = 1');
    }
}
