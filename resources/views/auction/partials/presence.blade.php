{{--
    Quién está en la sala: canal de presencia `auction.presence`.

    Solo se suma quien tiene identidad (un nombre o el rol de subastador):
    `/broadcasting/auth` lo autoriza con la sesión. Sin identidad se ve el aviso
    y nada más; la subasta sigue en vivo por el canal público.

    Los ganchos `data-*` son el contrato con resources/js/presence.js: el JS solo
    escribe texto en ellos (nunca HTML) y alterna `hidden`.
--}}
@php($canJoin = ($viewer ?? null) !== null || ($isHost ?? false))

<section data-presence @if ($canJoin) data-presence-join="1" @endif
         class="rounded-xl border border-slate-800 bg-slate-900/40 p-5">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-400">En la sala</h2>
        <p class="text-xs text-slate-500" data-presence-count aria-live="polite">—</p>
    </div>

    @if ($canJoin)
        <ul data-presence-members class="mt-4 flex flex-wrap gap-2 text-sm"></ul>
    @else
        <p class="mt-4 text-sm text-slate-600">Elegí un nombre para ver quién más está conectado.</p>
    @endif
</section>
