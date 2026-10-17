{{--
    Historial de lotes cerrados (RF-13), compartido por las dos pantallas.

    Viene dentro del snapshot público: se dibuja al cargar (GET /auction/state)
    y se refresca con cada AuctionStateUpdated, igual que el lote activo.

    Los ganchos `data-*` son el contrato con resources/js/auction.js: el JS solo
    escribe texto en ellos (nunca HTML) y alterna `hidden`.
--}}
<section class="rounded-xl border border-slate-800 bg-slate-900/40 p-5">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-400">Historial de lotes</h2>
        <p class="text-xs text-slate-600">Cerrados, del más nuevo al más viejo.</p>
    </div>

    <p data-history-empty class="mt-4 text-sm text-slate-600">Todavía no se cerró ningún lote.</p>

    <ul data-history class="mt-4 space-y-2 text-sm" aria-live="polite"></ul>
</section>
