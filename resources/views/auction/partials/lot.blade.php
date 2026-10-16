{{--
    Panel del lote activo, compartido por las dos pantallas.

    Los ganchos `data-*` son el contrato con resources/js/auction.js: el JS solo
    escribe texto en ellos (nunca HTML) y alterna `hidden`.
--}}
<section data-lot-empty class="rounded-xl border border-dashed border-slate-800 bg-slate-900/20 p-6 text-sm text-slate-500">
    Todavía no hay un lote abierto. Cuando el subastador abra uno, aparece acá al instante.
</section>

<section data-lot-panel hidden class="rounded-xl border border-slate-800 bg-slate-900/40 p-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-400">Lote</h2>
            <p data-lot-title class="mt-2 text-xl font-semibold tracking-tight">—</p>
            <p data-lot-description class="mt-1 max-w-prose text-sm text-slate-500"></p>
        </div>
        <p data-lot-status class="rounded-md border border-slate-700 px-2 py-1 text-xs uppercase tracking-wide text-slate-300">—</p>
    </div>

    <div class="mt-4 flex items-baseline gap-3">
        <span data-lot-price class="font-mono text-3xl font-semibold">—</span>
        <span class="text-xs uppercase tracking-wide text-slate-500" data-lot-price-label>precio actual</span>
    </div>

    <dl class="mt-4 grid gap-2 text-sm">
        <div class="flex items-center justify-between border-b border-dashed border-slate-800 pb-2">
            <dt class="text-slate-400">Líder</dt>
            <dd data-lot-leader class="font-medium">sin pujas</dd>
        </div>
        <div class="flex items-center justify-between border-b border-dashed border-slate-800 pb-2">
            <dt class="text-slate-400">Cierra a las</dt>
            <dd data-lot-ends class="font-mono">—</dd>
        </div>
        <div data-lot-winner-row hidden class="flex items-center justify-between">
            <dt class="text-slate-400">Ganador</dt>
            <dd data-lot-winner class="font-medium">—</dd>
        </div>
    </dl>
</section>
