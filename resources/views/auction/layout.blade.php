<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Subasta') · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/auction.js'])
</head>
<body class="h-full bg-slate-950 text-slate-100 antialiased">
    <div class="mx-auto flex min-h-full max-w-5xl flex-col gap-6 p-4 sm:p-6">
        <header class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-800 pb-4">
            <div class="flex items-center gap-3">
                <span class="inline-block size-3 rounded-full bg-amber-400 shadow-[0_0_12px] shadow-amber-400/70"></span>
                <span class="text-lg font-semibold tracking-tight">{{ config('app.name') }}</span>
            </div>

            <nav class="flex items-center gap-1 text-sm">
                @foreach (['auction.emisor' => 'Subastador', 'auction.receptor' => 'Pujador'] as $route => $label)
                    <a href="{{ route($route) }}"
                       @class([
                           'rounded-md px-3 py-1.5 transition',
                           'bg-slate-800 text-white' => request()->routeIs($route),
                           'text-slate-400 hover:bg-slate-900 hover:text-slate-200' => ! request()->routeIs($route),
                       ])
                       @if (request()->routeIs($route)) aria-current="page" @endif>
                        {{ $label }}
                    </a>
                @endforeach
            </nav>

            <p class="flex items-center gap-2 text-xs text-slate-400">
                <span class="inline-block size-2 rounded-full bg-slate-600" data-connection-dot></span>
                <span data-connection-status>Sin conectar</span>
            </p>
        </header>

        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-800 bg-slate-900/40 px-4 py-2 text-xs text-slate-400">
            <p class="flex flex-wrap items-baseline gap-2">
                <span class="uppercase tracking-wide text-slate-500">Último evento</span>
                <span data-last-event>—</span>
            </p>
            <p>
                <span class="uppercase tracking-wide text-slate-500">Versión</span>
                <strong data-state-version class="font-mono">—</strong>
            </p>
        </div>

        <main class="flex-1">
            @yield('content')
        </main>

        @if (app()->environment('local'))
            <section class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-dashed border-amber-700/60 bg-amber-950/20 px-4 py-3 text-xs text-amber-200">
                <p>
                    <strong class="uppercase tracking-wide">Solo desarrollo</strong>
                    — emite un evento <code>AuctionStateUpdated</code> desde el servidor para comprobar Reverb + Echo.
                </p>
                <button type="button"
                        data-dev-broadcast
                        class="rounded-md border border-amber-600/60 bg-amber-900/40 px-3 py-1.5 font-medium text-amber-100 transition hover:bg-amber-900 disabled:opacity-50">
                    Emitir evento de prueba
                </button>
            </section>
        @endif

        <footer class="border-t border-slate-800 pt-4 text-xs text-slate-500">
            El servidor es la única autoridad: el navegador solo pide acciones y dibuja el estado recibido.
        </footer>
    </div>

    @stack('scripts')
</body>
</html>
