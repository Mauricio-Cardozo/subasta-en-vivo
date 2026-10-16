@extends('auction.layout')

@section('title', 'Pujador')

@section('content')
    <div class="grid gap-6 lg:grid-cols-2">
        <section class="rounded-xl border border-slate-800 bg-slate-900/40 p-5">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-400">Lote activo</h2>

            <p data-lot-title class="mt-2 text-xl font-semibold tracking-tight">Sin lote abierto</p>

            <div class="mt-4 flex items-baseline gap-3">
                <span data-lot-price class="font-mono text-3xl font-semibold">—</span>
                <span class="text-xs uppercase tracking-wide text-slate-500">precio actual</span>
            </div>

            <div class="mt-4 grid gap-2 text-sm">
                <p class="flex items-center justify-between border-b border-dashed border-slate-800 pb-2">
                    <span class="text-slate-400">Líder</span>
                    <strong data-lot-leader class="font-medium">—</strong>
                </p>
                <p class="flex items-center justify-between border-b border-dashed border-slate-800 pb-2">
                    <span class="text-slate-400">Cierra en</span>
                    <strong data-lot-countdown class="font-mono">—</strong>
                </p>
                <p class="flex items-center justify-between">
                    <span class="text-slate-400">Tu saldo</span>
                    <strong data-balance class="font-mono">—</strong>
                </p>
            </div>

            <p data-bid-error class="mt-4 hidden rounded-md border border-rose-800 bg-rose-950/40 px-3 py-2 text-sm text-rose-300"></p>
        </section>

        <section class="rounded-xl border border-slate-800 bg-slate-900/40 p-5">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-400">Pujas recientes</h2>
            <ul data-bids class="mt-4 space-y-2 text-sm text-slate-400">
                <li class="text-slate-600">Todavía no hay pujas.</li>
            </ul>
        </section>
    </div>
@endsection
