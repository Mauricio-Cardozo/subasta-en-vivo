@extends('auction.layout')

@section('title', 'Subastador')

@section('content')
    <div class="grid gap-6 lg:grid-cols-2">
        <section class="rounded-xl border border-slate-800 bg-slate-900/40 p-5">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-400">Abrir lote</h2>
            <p class="mt-1 text-sm text-slate-500">
                El subastador abre un lote con título, precio inicial, incremento mínimo y duración.
            </p>

            <div class="mt-4 grid gap-3 text-sm">
                <p class="flex items-center justify-between border-b border-dashed border-slate-800 pb-2">
                    <span class="text-slate-400">Lote activo</span>
                    <strong data-lot-title class="font-medium">—</strong>
                </p>
                <p class="flex items-center justify-between border-b border-dashed border-slate-800 pb-2">
                    <span class="text-slate-400">Precio actual</span>
                    <strong data-lot-price class="font-mono">—</strong>
                </p>
                <p class="flex items-center justify-between border-b border-dashed border-slate-800 pb-2">
                    <span class="text-slate-400">Tiempo restante</span>
                    <strong data-lot-countdown class="font-mono">—</strong>
                </p>
                <p class="flex items-center justify-between">
                    <span class="text-slate-400">Líder</span>
                    <strong data-lot-leader class="font-medium">—</strong>
                </p>
            </div>
        </section>

        <section class="rounded-xl border border-slate-800 bg-slate-900/40 p-5">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-400">Pujas recientes</h2>
            <ul data-bids class="mt-4 space-y-2 text-sm text-slate-400">
                <li class="text-slate-600">Todavía no hay pujas.</li>
            </ul>
        </section>
    </div>
@endsection
