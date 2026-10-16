@extends('auction.layout')

@section('title', 'Subastador')

@section('content')
    <div class="grid gap-6 lg:grid-cols-2">
        @if ($isHost)
            <section class="rounded-xl border border-slate-800 bg-slate-900/40 p-5">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-400">Abrir lote</h2>
                <p class="mt-1 text-sm text-slate-500">
                    Si ya hay un lote abierto, el servidor rechaza la apertura: primero hay que cerrarlo.
                </p>

                <form data-open-lot class="mt-4 grid gap-3 text-sm">
                    <label class="grid gap-1">
                        <span class="text-slate-400">Título</span>
                        <input name="title" type="text" required maxlength="80"
                               class="rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 outline-none focus:border-amber-500">
                    </label>

                    <label class="grid gap-1">
                        <span class="text-slate-400">Descripción <span class="text-slate-600">(opcional)</span></span>
                        <textarea name="description" rows="2" maxlength="200"
                                  class="rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 outline-none focus:border-amber-500"></textarea>
                    </label>

                    <div class="grid gap-3 sm:grid-cols-3">
                        <label class="grid gap-1">
                            <span class="text-slate-400">Precio inicial</span>
                            <input name="start_price_cents" type="number" min="1" step="1" required
                                   value="{{ config('auction.lot.min_increment_cents') * 50 }}"
                                   class="rounded-md border border-slate-700 bg-slate-950 px-3 py-2 font-mono text-slate-100 outline-none focus:border-amber-500">
                            <span class="text-xs text-slate-600">en centavos</span>
                        </label>

                        <label class="grid gap-1">
                            <span class="text-slate-400">Incremento</span>
                            <input name="min_increment_cents" type="number" min="1" step="1"
                                   value="{{ config('auction.lot.min_increment_cents') }}"
                                   class="rounded-md border border-slate-700 bg-slate-950 px-3 py-2 font-mono text-slate-100 outline-none focus:border-amber-500">
                            <span class="text-xs text-slate-600">en centavos</span>
                        </label>

                        <label class="grid gap-1">
                            <span class="text-slate-400">Duración</span>
                            <input name="duration_seconds" type="number" min="5" max="600" step="1"
                                   value="{{ config('auction.lot.duration_seconds') }}"
                                   class="rounded-md border border-slate-700 bg-slate-950 px-3 py-2 font-mono text-slate-100 outline-none focus:border-amber-500">
                            <span class="text-xs text-slate-600">segundos</span>
                        </label>
                    </div>

                    <p data-form-error hidden class="rounded-md border border-rose-800 bg-rose-950/40 px-3 py-2 text-rose-300"></p>

                    <button type="submit"
                            class="justify-self-start rounded-md bg-amber-500 px-4 py-2 font-medium text-slate-950 transition hover:bg-amber-400 disabled:opacity-50">
                        Abrir lote
                    </button>
                </form>
            </section>
        @else
            <section class="rounded-xl border border-slate-800 bg-slate-900/40 p-5">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-400">Entrar como subastador</h2>
                <p class="mt-1 text-sm text-slate-500">
                    Abrir y cerrar lotes requiere el PIN del subastador. El rol vive en la sesión del servidor.
                </p>

                <form data-host-login class="mt-4 grid gap-3 text-sm">
                    <label class="grid gap-1">
                        <span class="text-slate-400">PIN</span>
                        <input name="pin" type="password" required autocomplete="off"
                               class="rounded-md border border-slate-700 bg-slate-950 px-3 py-2 font-mono text-slate-100 outline-none focus:border-amber-500">
                    </label>

                    <p data-form-error hidden class="rounded-md border border-rose-800 bg-rose-950/40 px-3 py-2 text-rose-300"></p>

                    <button type="submit"
                            class="justify-self-start rounded-md bg-amber-500 px-4 py-2 font-medium text-slate-950 transition hover:bg-amber-400 disabled:opacity-50">
                        Entrar
                    </button>
                </form>
            </section>
        @endif

        <div class="grid gap-6">
            @include('auction.partials.lot')
        </div>
    </div>
@endsection
