@extends('auction.layout')

@section('title', 'Panel')

@section('content')
    {{--
        Panel del subastador (RF-18). Se arma en el servidor y es de solo
        lectura: recargar la página trae los datos del momento. Todo texto pasa
        por {{ }} (escapado), incluidos los nombres y el SQL.
    --}}
    @php($maxBids = max(1, ...array_column($bidsPerMinute, 'count')))

    <div class="grid gap-6">
        <section class="rounded-xl border border-slate-800 bg-slate-900/40 p-5">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-400">Pujas por minuto</h2>
                <p class="text-xs text-slate-500">Últimos {{ count($bidsPerMinute) }} minutos · pujas aceptadas</p>
            </div>

            <ol class="mt-4 grid gap-1 text-xs" data-admin-bids>
                @foreach ($bidsPerMinute as $point)
                    <li class="grid grid-cols-[3rem_1fr_2rem] items-center gap-2">
                        <span class="font-mono text-slate-500">{{ $point['minute'] }}</span>
                        <span class="h-3 rounded-sm bg-slate-800">
                            <span class="block h-3 rounded-sm bg-amber-400"
                                  style="width: {{ round($point['count'] / $maxBids * 100) }}%"></span>
                        </span>
                        <span class="text-right font-mono text-slate-300">{{ $point['count'] }}</span>
                    </li>
                @endforeach
            </ol>
        </section>

        <section class="rounded-xl border border-slate-800 bg-slate-900/40 p-5">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-400">Últimas acciones</h2>
                <p class="text-xs text-slate-500">auction_actions · la más nueva primero</p>
            </div>

            @if ($actions === [])
                <p class="mt-4 text-sm text-slate-600">Todavía no hay acciones.</p>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-left text-xs" data-admin-actions>
                        <thead class="text-slate-500">
                            <tr>
                                <th class="py-1 pr-3 font-medium">Hora</th>
                                <th class="py-1 pr-3 font-medium">Tipo</th>
                                <th class="py-1 pr-3 font-medium">Quién</th>
                                <th class="py-1 pr-3 font-medium">Resultado</th>
                                <th class="py-1 pr-3 font-medium">IP</th>
                                <th class="py-1 font-medium">Correlation ID</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800 text-slate-300">
                            @foreach ($actions as $action)
                                <tr>
                                    <td class="py-1 pr-3 font-mono">{{ $action['at'] }}</td>
                                    <td class="py-1 pr-3 font-mono">{{ $action['type'] }}</td>
                                    <td class="py-1 pr-3">{{ $action['actor'] }}</td>
                                    <td @class([
                                        'py-1 pr-3',
                                        'text-emerald-300' => $action['outcome'] === 'aceptada',
                                        'text-rose-300' => $action['outcome'] === 'rechazada',
                                    ])>
                                        {{ $action['outcome'] }}@if ($action['reason']) · {{ $action['reason'] }}@endif
                                    </td>
                                    <td class="py-1 pr-3 font-mono text-slate-500">{{ $action['ip'] ?? '—' }}</td>
                                    <td class="py-1 font-mono text-slate-500">{{ $action['correlation_id'] ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="rounded-xl border border-slate-800 bg-slate-900/40 p-5">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-400">Consultas lentas</h2>
                <p class="text-xs text-slate-500">Canal sql · más de {{ $slowMs }} ms · sin valores</p>
            </div>

            @if ($slowQueries === [])
                <p class="mt-4 text-sm text-slate-600">Hoy no hubo consultas lentas.</p>
            @else
                <ul class="mt-4 space-y-2 text-xs" data-admin-slow>
                    @foreach ($slowQueries as $query)
                        <li class="rounded-md border border-slate-800 p-2">
                            <p class="flex flex-wrap gap-3 text-slate-500">
                                <span class="font-mono">{{ $query['at'] }}</span>
                                <span class="font-mono text-amber-300">{{ number_format($query['ms'], 1) }} ms</span>
                                <span class="font-mono">{{ $query['correlation_id'] ?? '—' }}</span>
                            </p>
                            <code class="mt-1 block break-all text-slate-300">{{ $query['sql'] }}</code>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
@endsection
