<?php

namespace Tests\Feature;

use App\Events\ChatMessageDeleted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Trazabilidad de broadcasts (RF-16) y de consultas lentas (RF-17).
 */
class ObservabilityLogTest extends TestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logDir = sys_get_temp_dir().'/auction-observability-'.uniqid();
        mkdir($this->logDir);

        config()->set('logging.channels.broadcast.handler_with.filename', $this->logDir.'/broadcast.log');
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

    /**
     * @return list<array<string, mixed>>
     */
    private function records(string $channel): array
    {
        $records = [];

        foreach (glob("{$this->logDir}/{$channel}-*.log") ?: [] as $file) {
            foreach (array_filter(explode("\n", (string) file_get_contents($file))) as $line) {
                $records[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            }
        }

        return $records;
    }

    public function test_every_broadcast_is_logged_with_event_channel_payload_and_origin(): void
    {
        Log::shareContext(['correlation_id' => 'cid-evento']);

        broadcast(new ChatMessageDeleted(7));

        [$record] = $this->records('broadcast');

        $this->assertSame('broadcast.sent', $record['message']);
        $this->assertSame('ChatMessageDeleted', $record['context']['event']);
        $this->assertSame(['auction'], $record['context']['channels']);
        $this->assertSame(['id' => 7], $record['context']['payload']);
        $this->assertSame('console', $record['context']['origin']);
        $this->assertSame('cid-evento', $record['context']['correlation_id']);
    }

    public function test_a_slow_query_is_logged_without_its_values(): void
    {
        config()->set('auction.sql.slow_ms', 0);

        DB::select('select ? as secreto', ['dato-privado']);

        $records = array_values(array_filter(
            $this->records('sql'),
            fn (array $record): bool => str_contains($record['context']['sql'], 'secreto'),
        ));

        $this->assertCount(1, $records);
        $this->assertSame('sql.slow', $records[0]['message']);
        $this->assertSame('select ? as secreto', $records[0]['context']['sql']);
        $this->assertArrayHasKey('ms', $records[0]['context']);
        $this->assertStringNotContainsString('dato-privado', json_encode($records, JSON_THROW_ON_ERROR));
    }

    public function test_a_fast_query_is_not_logged(): void
    {
        config()->set('auction.sql.slow_ms', 60_000);

        DB::select('select 1');

        $this->assertSame([], $this->records('sql'));
    }
}
