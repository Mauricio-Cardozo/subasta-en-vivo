@extends('auction.layout')

@section('title', 'Pujador')

@section('content')
    <div class="grid gap-6 lg:grid-cols-2">
        <div class="grid gap-6">
            @if (! ($viewer ?? null))
                <section class="rounded-xl border border-slate-800 bg-slate-900/40 p-5">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-400">Elegí tu nombre</h2>
                    <p class="mt-1 text-sm text-slate-500">
                        Con tu nombre entrás a la sala con un saldo virtual. El nombre no se puede repetir.
                    </p>

                    <form data-join class="mt-4 grid gap-3 text-sm">
                        <label class="grid gap-1">
                            <span class="text-slate-400">Nombre</span>
                            <input name="name" type="text" required maxlength="20" autocomplete="off"
                                   class="rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 outline-none focus:border-amber-500">
                        </label>

                        <p data-form-error hidden class="rounded-md border border-rose-800 bg-rose-950/40 px-3 py-2 text-rose-300"></p>

                        <button type="submit"
                                class="justify-self-start rounded-md bg-amber-500 px-4 py-2 font-medium text-slate-950 transition hover:bg-amber-400 disabled:opacity-50">
                            Entrar a la subasta
                        </button>
                    </form>
                </section>
            @endif

            <section class="rounded-xl border border-slate-800 bg-slate-900/40 p-5">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-400">Tu saldo</h2>
                <p class="mt-2 font-mono text-3xl font-semibold" data-balance>—</p>
                <p class="mt-1 text-xs text-slate-600">
                    Saldo virtual para pujar; el servidor lo descuenta cuando ganás un lote.
                </p>
            </section>

            @if ($viewer ?? null)
                {{--
                    El formulario existe porque la sesión ya tiene un nombre. El JS solo lo
                    muestra cuando hay un lote abierto y no sos el líder. El pujador propone
                    un monto (RF-03) y el servidor lo valida contra el mínimo y el saldo:
                    el navegador nunca decide si la puja vale (RNF-01).
                --}}
                <section data-bid-section hidden class="rounded-xl border border-slate-800 bg-slate-900/40 p-5">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-400">Puja</h2>
                    <p class="mt-1 text-sm text-slate-500">
                        Ofrecé cualquier monto desde la puja mínima. El servidor lo valida con
                        el precio y tu saldo al momento de recibirlo.
                    </p>

                    <form data-bid class="mt-4 grid gap-3 text-sm">
                        <p class="font-mono text-2xl font-semibold text-amber-300" data-bid-minimum>—</p>

                        <label class="grid gap-1">
                            <span class="text-slate-400">Tu oferta (pesos)</span>
                            <input type="number" name="amount" data-bid-amount inputmode="decimal"
                                   min="0.01" step="0.01" required
                                   class="rounded-md border border-slate-700 bg-slate-950 px-3 py-2 font-mono text-slate-100 focus:border-amber-400 focus:outline-none">
                        </label>

                        <p data-form-error hidden class="rounded-md border border-rose-800 bg-rose-950/40 px-3 py-2 text-rose-300"></p>

                        <button type="submit"
                                class="justify-self-start rounded-md bg-amber-500 px-4 py-2 font-medium text-slate-950 transition hover:bg-amber-400 disabled:opacity-50">
                            Pujar
                        </button>
                    </form>
                </section>
            @endif
        </div>

        <div class="grid gap-6">
            @include('auction.partials.lot')

            <section class="rounded-xl border border-slate-800 bg-slate-900/40 p-5">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-400">Pujas recientes</h2>
                <ul data-bids class="mt-4 space-y-2 text-sm text-slate-400">
                    <li class="text-slate-600" data-bids-empty>Todavía no hay pujas.</li>
                </ul>
            </section>

            @include('auction.partials.presence')

            @include('auction.partials.history')
        </div>

        <div class="lg:col-span-2">
            @include('auction.partials.chat')
        </div>
    </div>
@endsection
