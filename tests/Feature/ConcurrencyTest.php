<?php

namespace Tests\Feature;

use App\Models\AuctionAction;
use App\Models\AuctionRoom;
use App\Models\Bid;
use App\Models\Lot;
use App\Models\Participant;
use App\Services\AuctionService;
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

    public function test_a_concurrent_duplicate_action_is_recorded_once(): void
    {
        $results = array_values(Concurrency::run([
            $this->claimTask('participant:1', 'la-misma-accion'),
            $this->claimTask('participant:1', 'la-misma-accion'),
        ]));

        $this->assertSame(1, count(array_filter($results, fn (array $result): bool => $result['ok'])));
        $this->assertSame(1, AuctionAction::query()->count());
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
