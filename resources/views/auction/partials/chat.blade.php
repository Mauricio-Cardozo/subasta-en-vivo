{{--
    Chat de la sala (RF-11), compartido por las dos pantallas.

    El historial es público (como el estado), pero publicar requiere identidad:
    por eso el formulario solo se dibuja con un nombre o con el rol de
    subastador. El subastador modera: la marca `data-moderate="1"` habilita el
    borrado en el cliente, y el servidor lo vuelve a comprobar al borrar (el
    navegador nunca es la autoridad).

    Los ganchos `data-*` son el contrato con resources/js/chat.js: el JS solo
    escribe texto en ellos (nunca HTML) y alterna `hidden`.
--}}
@php($canChat = ($viewer ?? null) !== null || ($isHost ?? false))

<section data-chat @if ($isHost ?? false) data-moderate="1" @endif
         class="rounded-xl border border-slate-800 bg-slate-900/40 p-5">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-400">Chat</h2>
        <p class="text-xs text-slate-600">
            @if ($isHost ?? false)
                Podés borrar cualquier mensaje.
            @else
                En orden de llegada.
            @endif
        </p>
    </div>

    <p data-chat-empty class="mt-4 text-sm text-slate-600">Todavía no hay mensajes.</p>

    <ul data-chat-messages class="mt-4 space-y-2 text-sm" aria-live="polite"></ul>

    @if ($canChat)
        <form data-chat-form class="mt-4 grid gap-2 text-sm">
            <label class="grid gap-1">
                <span class="sr-only">Mensaje</span>
                <input data-chat-field name="body" type="text" required
                       maxlength="{{ config('auction.chat.max_length') }}"
                       placeholder="Escribí un mensaje…" autocomplete="off"
                       class="rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 outline-none focus:border-amber-500">
            </label>

            <p data-chat-error hidden class="rounded-md border border-rose-800 bg-rose-950/40 px-3 py-2 text-rose-300"></p>

            <button type="submit"
                    class="justify-self-start rounded-md bg-amber-500 px-4 py-2 font-medium text-slate-950 transition hover:bg-amber-400 disabled:opacity-50">
                Enviar
            </button>
        </form>
    @else
        <p class="mt-4 text-xs text-slate-600">Entrá con un nombre para participar del chat.</p>
    @endif
</section>
