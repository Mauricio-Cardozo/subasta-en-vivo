<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\RotatingFileHandler;
use Tests\TestCase;

/**
 * Canales JSON del proyecto.
 *
 * Cada registro es una línea JSON legible por máquinas y el `correlation_id`
 * viaja en el contexto de todos los canales.
 */
class JsonLogChannelTest extends TestCase
{
    public function test_the_project_channels_are_json_and_rotate(): void
    {
        foreach (['auction', 'audit', 'broadcast', 'sql'] as $channel) {
            $this->assertSame(JsonFormatter::class, config("logging.channels.{$channel}.formatter"));
            $this->assertSame(RotatingFileHandler::class, config("logging.channels.{$channel}.handler"));
            $this->assertSame(14, config("logging.channels.{$channel}.handler_with.maxFiles"));
            $this->assertStringEndsWith(
                "/{$channel}.log",
                config("logging.channels.{$channel}.handler_with.filename"),
            );
        }
    }

    public function test_the_tests_write_their_logs_apart(): void
    {
        // 7: los registros de los tests no se mezclan con los de la app.
        $this->assertSame(
            base_path('storage/logs/testing/audit.log'),
            config('logging.channels.audit.handler_with.filename'),
        );
    }

    public function test_a_record_is_one_json_line_with_the_shared_context(): void
    {
        $dir = sys_get_temp_dir().'/auction-log-'.uniqid();
        mkdir($dir);
        config()->set('logging.channels.audit.handler_with.filename', $dir.'/audit.log');

        Log::shareContext(['correlation_id' => 'cid-123']);
        Log::channel('audit')->info('accion.prueba', ['outcome' => 'accepted']);

        // El handler rota a diario: escribe en `audit-YYYY-MM-DD.log`.
        $files = glob($dir.'/audit-*.log') ?: [];
        $this->assertCount(1, $files, 'Se esperaba un archivo de log rotado.');

        $contents = trim((string) file_get_contents($files[0]));

        foreach (glob($dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        rmdir($dir);

        $this->assertStringNotContainsString("\n", $contents, 'El registro debe ocupar una sola línea.');

        $record = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('accion.prueba', $record['message']);
        $this->assertSame('INFO', $record['level_name']);
        $this->assertSame('accepted', $record['context']['outcome']);
        $this->assertSame('cid-123', $record['context']['correlation_id']);
    }
}
