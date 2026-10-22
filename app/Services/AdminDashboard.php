<?php

namespace App\Services;

use App\Models\AuctionAction;
use App\Models\Bid;
use Illuminate\Support\Carbon;

/**
 * Datos del panel `/admin` (RF-18): ritmo de pujas, últimas acciones
 * auditadas y consultas lentas. Solo lectura: el panel no cambia nada.
 */
class AdminDashboard
{
    /**
     * Pujas aceptadas por minuto en los últimos `$minutes` minutos, del más
     * viejo al más nuevo y con los minutos sin pujas en cero. Se agrupa en PHP
     * para no depender de las funciones de fecha de cada motor.
     *
     * @return list<array{minute: string, count: int}>
     */
    public function bidsPerMinute(int $minutes = 10): array
    {
        $since = now()->startOfMinute()->subMinutes($minutes - 1);

        $counts = Bid::query()
            ->where('created_at', '>=', $since)
            ->pluck('created_at')
            ->countBy(fn (Carbon $at): string => $at->format('H:i'));

        $series = [];

        for ($minute = $since->copy(); $minute->lte(now()); $minute->addMinute()) {
            $label = $minute->format('H:i');
            $series[] = ['minute' => $label, 'count' => (int) ($counts[$label] ?? 0)];
        }

        return $series;
    }

    /**
     * Últimas acciones de `auction_actions`, la más nueva primero.
     *
     * @return list<array{at: string, type: string, actor: string, outcome: string, reason: string|null, ip: string|null, correlation_id: string|null}>
     */
    public function recentActions(int $limit = 20): array
    {
        return AuctionAction::query()
            ->with('participant')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (AuctionAction $action): array => [
                'at' => $action->created_at?->format('H:i:s') ?? '—',
                'type' => $action->type,
                'actor' => $action->participant?->name
                    ?? (str_starts_with($action->actor_key, 'host:') ? config('auction.chat.host_name') : $action->actor_key),
                'outcome' => match ($action->accepted) {
                    true => 'aceptada',
                    false => 'rechazada',
                    null => 'en curso',
                },
                'reason' => $action->reason,
                'ip' => $action->ip,
                'correlation_id' => $action->correlation_id,
            ])
            ->all();
    }

    /**
     * Últimas consultas lentas del canal `sql` (RF-17), la más nueva primero.
     * Se leen del archivo del día; sin archivo, no hay consultas lentas.
     *
     * @return list<array{at: string, ms: float, sql: string, correlation_id: string|null}>
     */
    public function slowQueries(int $limit = 20): array
    {
        $base = (string) config('logging.channels.sql.handler_with.filename');
        $files = glob(preg_replace('/\.log$/', '-*.log', $base)) ?: [];

        if ($files === []) {
            return [];
        }

        sort($files);
        $lines = array_filter(explode("\n", (string) file_get_contents(end($files))));

        $queries = [];

        foreach (array_reverse($lines) as $line) {
            $record = json_decode($line, true);

            if (! is_array($record) || ($record['message'] ?? null) !== 'sql.slow') {
                continue;
            }

            $queries[] = [
                'at' => Carbon::parse($record['datetime'] ?? 'now')->format('H:i:s'),
                'ms' => (float) ($record['context']['ms'] ?? 0),
                'sql' => (string) ($record['context']['sql'] ?? ''),
                'correlation_id' => $record['context']['correlation_id'] ?? null,
            ];

            if (count($queries) === $limit) {
                break;
            }
        }

        return $queries;
    }
}
