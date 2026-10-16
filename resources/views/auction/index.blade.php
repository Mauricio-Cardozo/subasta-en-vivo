@extends('auction.layout')

@section('title', 'Elegir rol')

@section('content')
    <div class="grid gap-6 sm:grid-cols-2">
        <a href="{{ route('auction.emisor') }}"
           class="group rounded-xl border border-slate-800 bg-slate-900/40 p-6 transition hover:border-amber-500/60 hover:bg-slate-900">
            <span class="text-xs font-semibold uppercase tracking-wide text-amber-400">Emisor</span>
            <h2 class="mt-2 text-xl font-semibold tracking-tight">Subastador</h2>
            <p class="mt-2 text-sm text-slate-400">
                Abre lotes con precio inicial, incremento mínimo y duración; ciérralos antes de tiempo y modera el chat.
            </p>
            <span class="mt-4 inline-block text-sm text-slate-500 group-hover:text-amber-300">Entrar a /emisor →</span>
        </a>

        <a href="{{ route('auction.receptor') }}"
           class="group rounded-xl border border-slate-800 bg-slate-900/40 p-6 transition hover:border-amber-500/60 hover:bg-slate-900">
            <span class="text-xs font-semibold uppercase tracking-wide text-amber-400">Receptor</span>
            <h2 class="mt-2 text-xl font-semibold tracking-tight">Pujador</h2>
            <p class="mt-2 text-sm text-slate-400">
                Ve el lote activo, el precio y el temporizador; puja con tu saldo virtual y chatea con la sala.
            </p>
            <span class="mt-4 inline-block text-sm text-slate-500 group-hover:text-amber-300">Entrar a /receptor →</span>
        </a>
    </div>
@endsection
