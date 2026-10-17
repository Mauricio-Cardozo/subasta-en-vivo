import './echo';
import './chat';

/**
 * Puente entre el canal `auction` y el DOM.
 *
 * El servidor es la única autoridad: acá no se calcula nada económico, solo se
 * dibuja el snapshot que llega por `GET /auction/state` o por
 * `AuctionStateUpdated` (app/Events/AuctionStateUpdated.php).
 *
 * Reglas del cliente:
 *  - Todo texto se pinta con `textContent`, nunca con `innerHTML`.
 *  - Un evento con `version` menor o igual a la mostrada se ignora.
 *  - Al (re)conectar se vuelve a pedir el estado por HTTP: Reverb no reemplaza
 *    al endpoint.
 *  - Un 422 se muestra como error; jamás se simula éxito.
 */
const state = {
    version: null,
    lot: null,
    bids: null,
    history: null,
    me: null,
};

/** Evita un segundo envío mientras la puja está en vuelo. */
let bidding = false;

/**
 * Ancla del temporizador. Se recalcula con el `remaining_ms` del servidor (no
 * con el reloj del navegador): al llegar un evento (una puja que extiende el
 * cierre, por ejemplo) el restante se reajusta solo.
 */
let countdownDeadline = null;

/** Evita pedir el cierre más de una vez por ancla al llegar a cero. */
let closeRequested = false;

/** Un `action_id` por intento: repetir la acción nunca puja dos veces (4.7). */
function newActionId() {
    if (typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    const bytes = crypto.getRandomValues(new Uint8Array(16));

    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;

    const hex = [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('');

    return [
        hex.slice(0, 8),
        hex.slice(8, 12),
        hex.slice(12, 16),
        hex.slice(16, 20),
        hex.slice(20),
    ].join('-');
}

const endpoints = {
    state: '/auction/state',
    join: '/join',
    hostLogin: '/host/login',
    openLot: '/auction/lots',
    closeLot: '/auction/lots/close',
    bid: '/auction/bids',
    devBroadcast: '/dev/broadcast',
};

const selectors = {
    status: '[data-connection-status]',
    dot: '[data-connection-dot]',
    lastEvent: '[data-last-event]',
    version: '[data-state-version]',
    lotPanel: '[data-lot-panel]',
    lotEmpty: '[data-lot-empty]',
    lotTitle: '[data-lot-title]',
    lotDescription: '[data-lot-description]',
    lotStatus: '[data-lot-status]',
    lotPrice: '[data-lot-price]',
    lotPriceLabel: '[data-lot-price-label]',
    lotCountdown: '[data-lot-countdown]',
    lotLeader: '[data-lot-leader]',
    lotEnds: '[data-lot-ends]',
    lotWinnerRow: '[data-lot-winner-row]',
    lotWinner: '[data-lot-winner]',
    balance: '[data-balance]',
    bids: '[data-bids]',
    bidsEmpty: '[data-bids-empty]',
    history: '[data-history]',
    historyEmpty: '[data-history-empty]',
    bidSection: '[data-bid-section]',
    bidMinimum: '[data-bid-minimum]',
    bidForm: '[data-bid]',
    closeSection: '[data-close-section]',
    closeForm: '[data-close-lot]',
};

/**
 * Estados del servidor traducidos para mostrar (los valores del enum son
 * `open`, `sold`, `unsold`, `cancelled`).
 */
const statusLabels = {
    open: 'Abierto',
    sold: 'Vendido',
    unsold: 'Sin vender',
    cancelled: 'Cancelado',
};

/** Umbral para avisar que el cierre está encima. */
const COUNTDOWN_LOW_MS = 10_000;

/**
 * Estados de la conexión (Pusher/Echo) traducidos para el encabezado. El
 * indicador es **informativo**: las acciones van por HTTP y no se bloquean si el
 * WebSocket está caído; al reconectar se vuelve a pedir el estado.
 */
const connectionStates = {
    connected: { label: 'En vivo', tone: 'online' },
    connecting: { label: 'Conectando…', tone: 'waiting' },
    disconnected: { label: 'Reconectando…', tone: 'waiting' },
    unavailable: { label: 'Sin conexión', tone: 'offline' },
    failed: { label: 'Sin conexión', tone: 'offline' },
};

/** Color del punto por tono: verde en vivo, ámbar conectando, rojo caído. */
const connectionTones = {
    online: ['bg-emerald-400'],
    waiting: ['bg-amber-400'],
    offline: ['bg-rose-500'],
};

const connectionDotClasses = ['bg-slate-600', ...Object.values(connectionTones).flat()];

function find(selector) {
    return document.querySelector(selector);
}

function setText(selector, text) {
    const element = find(selector);

    if (element) {
        element.textContent = text;
    }
}

function toggle(selector, visible) {
    const element = find(selector);

    if (element) {
        element.hidden = ! visible;
    }
}

/**
 * Pinta el indicador de conexión: etiqueta y color del punto según el tono.
 */
function setConnection(label, tone = 'offline') {
    setText(selectors.status, label);

    const dot = find(selectors.dot);

    if (dot) {
        dot.classList.remove(...connectionDotClasses);
        dot.classList.add(...(connectionTones[tone] ?? connectionTones.offline));
    }
}

function describe(action) {
    if (! action) {
        return 'actualización';
    }

    return action.summary ?? action.type ?? 'actualización';
}

/**
 * Formatea centavos como pesos solo al mostrar (RNF-03: el servidor manda
 * enteros).
 */
function formatCents(cents) {
    return new Intl.NumberFormat('es-AR', {
        style: 'currency',
        currency: 'ARS',
    }).format(cents / 100);
}

function formatTime(iso) {
    if (! iso) {
        return '—';
    }

    const date = new Date(iso);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleTimeString('es-AR');
}

/** `m:ss` a partir de milisegundos restantes. */
function formatCountdown(remainingMs) {
    const total = Math.max(0, Math.ceil(remainingMs / 1000));
    const minutes = Math.floor(total / 60);
    const seconds = total % 60;

    return `${minutes}:${String(seconds).padStart(2, '0')}`;
}

function statusLabel(status) {
    return statusLabels[status] ?? status ?? '';
}

/** Color del estado de un lote cerrado del historial. */
function historyStatusClass(status) {
    const classes = {
        sold: 'text-emerald-300',
        unsold: 'text-slate-400',
        cancelled: 'text-rose-300',
    };

    return classes[status] ?? 'text-slate-400';
}

/** Reancla la cuenta regresiva desde `remaining_ms` del servidor (RF-08). */
function anchorCountdown(lot) {
    closeRequested = false;

    countdownDeadline = lot && lot.status === 'open' && typeof lot.remaining_ms === 'number'
        ? Date.now() + lot.remaining_ms
        : null;
}

/**
 * Dibuja el restante y, al llegar a cero, pide el estado: el cierre lo decide el
 * servidor (es perezoso), así que el navegador no lo simula.
 */
function renderCountdown() {
    const element = find(selectors.lotCountdown);

    if (element) {
        if (countdownDeadline === null) {
            element.textContent = '—';
        } else {
            const remaining = countdownDeadline - Date.now();

            element.textContent = formatCountdown(remaining);
            element.classList.toggle('text-rose-400', remaining <= COUNTDOWN_LOW_MS);
            element.classList.toggle('text-amber-300', remaining > COUNTDOWN_LOW_MS);
        }
    }

    if (countdownDeadline === null || closeRequested) {
        return;
    }

    if (countdownDeadline - Date.now() <= 0) {
        closeRequested = true;
        loadState();
    }
}

function renderLot(lot) {
    toggle(selectors.lotPanel, Boolean(lot));
    toggle(selectors.lotEmpty, ! lot);

    // La cuenta regresiva se reancla con cada estado: una puja que extiende el
    // cierre (anti-sniping) llega como `remaining_ms` nuevo.
    anchorCountdown(lot);
    renderCountdown();

    if (! lot) {
        return;
    }

    setText(selectors.lotTitle, lot.title ?? 'Sin título');
    setText(selectors.lotDescription, lot.description ?? '');
    setText(selectors.lotStatus, statusLabel(lot.status));

    // Hasta la primera puja el precio de referencia es el inicial (4.1).
    const hasBids = typeof lot.current_price_cents === 'number';

    setText(selectors.lotPrice, formatCents(lot.reference_price_cents));
    setText(selectors.lotPriceLabel, hasBids ? 'precio actual' : 'precio inicial');
    setText(selectors.lotLeader, lot.leader_name ?? 'sin pujas');
    setText(selectors.lotEnds, formatTime(lot.ends_at));

    toggle(selectors.lotWinnerRow, Boolean(lot.winner_name));
    setText(selectors.lotWinner, lot.winner_name ?? '');
}

function renderBids(bids) {
    const list = find(selectors.bids);

    if (! list) {
        return;
    }

    const items = Array.isArray(bids) ? bids : [];
    const empty = list.querySelector('[data-bids-empty]');

    // El snapshot trae la lista ordenada del servidor: se repinta entera.
    [...list.children].forEach((child) => {
        if (child !== empty) {
            child.remove();
        }
    });

    toggle(selectors.bidsEmpty, items.length === 0);

    items.forEach((bid) => {
        const row = document.createElement('li');
        row.className = 'flex items-center justify-between gap-3 rounded-md border border-slate-800 bg-slate-950/40 px-3 py-2';

        const author = document.createElement('span');
        author.className = 'truncate text-slate-300';
        author.textContent = bid.author_name ?? '—';

        const amount = document.createElement('span');
        amount.className = 'font-mono text-amber-300';
        amount.textContent = typeof bid.amount_cents === 'number' ? formatCents(bid.amount_cents) : '—';

        const at = document.createElement('span');
        at.className = 'text-xs text-slate-600';
        at.textContent = formatTime(bid.at);

        row.append(author, amount, at);
        list.append(row);
    });
}

/**
 * Historial de lotes cerrados (RF-13).
 *
 * Se repinta entero con cada snapshot (el servidor manda la lista ya ordenada y
 * acotada). Todo texto va con `textContent`, nunca HTML.
 */
function renderHistory(history) {
    const list = find(selectors.history);

    if (! list) {
        return;
    }

    const items = Array.isArray(history) ? history : [];

    while (list.firstChild) {
        list.removeChild(list.firstChild);
    }

    toggle(selectors.historyEmpty, items.length === 0);

    items.forEach((lot) => {
        const row = document.createElement('li');
        row.dataset.historyItem = '';
        row.className = 'flex flex-wrap items-center justify-between gap-2 rounded-md border border-slate-800 bg-slate-950/40 px-3 py-2';

        if (lot.closed_at) {
            row.title = new Date(lot.closed_at).toLocaleString('es-AR');
        }

        const title = document.createElement('span');
        title.className = 'min-w-0 flex-1 truncate text-slate-200';
        title.textContent = lot.title ?? '—';

        const detail = document.createElement('span');
        detail.className = 'flex items-center gap-3 text-xs text-slate-500';

        const status = document.createElement('span');
        status.className = historyStatusClass(lot.status);
        status.textContent = statusLabel(lot.status);

        const price = document.createElement('span');
        price.className = 'font-mono text-amber-300';
        price.textContent = typeof lot.final_price_cents === 'number' ? formatCents(lot.final_price_cents) : '—';

        detail.append(status, price);

        if (typeof lot.winner_name === 'string' && lot.winner_name !== '') {
            const winner = document.createElement('span');
            winner.textContent = `Ganó ${lot.winner_name}`;
            detail.append(winner);
        }

        row.append(title, detail);
        list.append(row);
    });
}

/**
 * ¿Esta sesión va ganando el lote?
 *
 * Se compara el nombre propio con `lot.leader_name` (público y único en la
 * sala) en vez de mirar `me.is_leader` a secas: el broadcast no lleva `me`, así
 * que ese booleano quedaría pegado al lote anterior al abrirse uno nuevo.
 */
function isLeader(lot) {
    const name = state.me?.name;

    if (! lot || ! name) {
        return Boolean(state.me?.is_leader);
    }

    return lot.leader_name === name;
}

/**
 * Puja mínima que aplicaría el servidor (4.1): iguala el precio inicial antes
 * de la primera puja y suma el incremento después. Es solo lo que se muestra en
 * el botón: el monto no viaja, lo calcula el servidor al atender la acción.
 */
function nextMinimum(lot) {
    if (! lot) {
        return null;
    }

    return typeof lot.current_price_cents === 'number'
        ? lot.current_price_cents + lot.min_increment_cents
        : lot.start_price_cents;
}

function renderBidForm() {
    const section = find(selectors.bidSection);

    if (! section) {
        return;
    }

    const lot = state.lot;
    const open = Boolean(lot) && lot.status === 'open';

    section.hidden = ! open;

    if (! open) {
        return;
    }

    const minimum = nextMinimum(lot);
    const leading = isLeader(lot);
    const form = find(selectors.bidForm);
    const button = form?.querySelector('button[type="submit"]');

    setText(selectors.bidMinimum, minimum === null ? '—' : `Puja mínima ${formatCents(minimum)}`);

    if (button) {
        button.textContent = leading
            ? 'Ya vas ganando este lote'
            : (minimum === null ? 'Pujar' : `Pujar ${formatCents(minimum)}`);
        button.disabled = leading || bidding;
    }
}

function renderCloseForm() {
    const lot = state.lot;

    // Solo existe para el subastador (la vista lo renderiza con el rol); con
    // lote abierto se ofrece cerrar.
    toggle(selectors.closeSection, Boolean(lot) && lot.status === 'open');
}

function render(snapshot) {
    // El bloque `me` solo viaja en la respuesta HTTP, no en el broadcast: se
    // aplica antes de cualquier rechazo por versión vieja, así el estado propio
    // nunca queda desfasado respecto del lote que sí llega en vivo.
    if (snapshot.me) {
        state.me = snapshot.me;
        setText(selectors.balance, typeof snapshot.me.balance_cents === 'number'
            ? formatCents(snapshot.me.balance_cents)
            : '—');
    }

    if (typeof snapshot.version === 'number') {
        // Los eventos desordenados o repetidos no se aplican (RNF-04).
        if (state.version !== null && snapshot.version <= state.version) {
            renderBidForm();
            renderCloseForm();

            return;
        }

        state.version = snapshot.version;
        setText(selectors.version, String(snapshot.version));
    }

    if ('lot' in snapshot) {
        const previousLotId = state.lot?.id ?? null;
        state.lot = snapshot.lot;

        // Un error de puja habla del lote que había cuando se envió: si el lote
        // cambió, el mensaje ya no describe nada de lo que se ve y se borra.
        if ((state.lot?.id ?? null) !== previousLotId) {
            const bidForm = find(selectors.bidForm);

            if (bidForm) {
                clearFormError(bidForm);
            }
        }
    }

    if ('bids' in snapshot) {
        state.bids = snapshot.bids;
    }

    if ('history' in snapshot) {
        state.history = snapshot.history;
    }

    renderLot(state.lot);
    renderBids(state.bids);
    renderHistory(state.history);
    renderBidForm();
    renderCloseForm();

    const lastEvent = find(selectors.lastEvent);

    if (lastEvent) {
        const time = formatTime(snapshot.server_now);
        lastEvent.textContent = `${describe(snapshot.action)} · ${time}`;
    }
}

function csrfToken() {
    return find('meta[name="csrf-token"]')?.content ?? '';
}

/**
 * Envía una acción y devuelve el cuerpo JSON, o lanza un Error con el mensaje
 * del servidor (incluidos los 422 de validación).
 */
async function postJson(url, body) {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            Accept: 'application/json',
        },
        body: JSON.stringify(body),
    });

    const payload = await response.json().catch(() => ({}));

    if (! response.ok) {
        throw new Error(firstErrorMessage(payload) ?? `El servidor respondió ${response.status}.`);
    }

    return payload;
}

function firstErrorMessage(payload) {
    if (typeof payload.message === 'string' && payload.message !== '') {
        return payload.message;
    }

    if (payload.errors && typeof payload.errors === 'object') {
        const first = Object.values(payload.errors)[0];

        return Array.isArray(first) ? first[0] : String(first);
    }

    return null;
}

function showFormError(form, message) {
    const box = form.querySelector('[data-form-error]');

    if (! box) {
        return;
    }

    box.textContent = message;
    box.hidden = false;
}

function clearFormError(form) {
    const box = form.querySelector('[data-form-error]');

    if (box) {
        box.textContent = '';
        box.hidden = true;
    }
}

/**
 * Envía un formulario con el patrón común: bloquear, informar y recargar.
 *
 * Tras un cambio de identidad se recarga la página: el HTML lo arma el servidor
 * según la sesión, así que el navegador nunca deduce permisos por su cuenta.
 */
function wireForm(selector, { url, payload, reload = false }) {
    const form = find(selector);

    if (! form) {
        return;
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearFormError(form);

        const button = form.querySelector('button[type="submit"]');

        if (button) {
            button.disabled = true;
        }

        try {
            await postJson(url, payload(new FormData(form)));

            if (reload) {
                window.location.reload();
            } else {
                form.reset();
            }
        } catch (error) {
            showFormError(form, error.message);
        } finally {
            if (button) {
                button.disabled = false;
            }
        }
    });
}

function numberField(data, name) {
    const raw = data.get(name);

    if (raw === null || raw === '') {
        return undefined;
    }

    return Number.parseInt(raw, 10);
}

/**
 * Puja del pujador.
 *
 * Solo manda `action_id`: el monto efectivo lo decide el servidor y un error
 * (422) se muestra tal cual, nunca se pinta como éxito. Al confirmarse, se
 * vuelve a pedir el estado por HTTP: el bloque `me` no viaja en el broadcast.
 */
function wireBidForm() {
    const form = find(selectors.bidForm);

    if (! form) {
        return;
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (bidding) {
            return;
        }

        clearFormError(form);
        bidding = true;
        renderBidForm();

        try {
            await postJson(endpoints.bid, { action_id: newActionId() });
            await loadState();
        } catch (error) {
            showFormError(form, error.message);
        } finally {
            bidding = false;
            renderBidForm();
        }
    });
}

function wireCloseLot() {
    const form = find(selectors.closeForm);

    if (! form) {
        return;
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearFormError(form);

        const button = form.querySelector('button[type="submit"]');

        if (button) {
            button.disabled = true;
        }

        try {
            // Solo un `action_id`: reintentar no cierra dos veces (4.7).
            await postJson(endpoints.closeLot, { action_id: newActionId() });
            await loadState();
        } catch (error) {
            showFormError(form, error.message);
        } finally {
            if (button) {
                button.disabled = false;
            }
        }
    });
}

function subscribe() {
    if (! window.Echo) {
        setConnection('Sin conexión', 'offline');

        return;
    }

    // Un único listener por página para el estado de la subasta.
    window.Echo.channel('auction').listen('.AuctionStateUpdated', (snapshot) => render(snapshot));
}

function watchConnection() {
    const connection = window.Echo?.connector?.pusher?.connection;

    if (! connection) {
        setConnection('Sin conexión', 'offline');

        return;
    }

    const paint = (pusherState) => {
        const info = connectionStates[pusherState] ?? { label: pusherState, tone: 'offline' };

        setConnection(info.label, info.tone);
    };

    paint(connection.state);

    connection.bind('state_change', ({ current }) => {
        paint(current);

        // Reverb no reemplaza al endpoint: al reconectar, el estado se pide de
        // nuevo por HTTP antes de confiar en los próximos eventos.
        if (current === 'connected') {
            loadState();
        }
    });
}

/**
 * Estado inicial: HTTP primero, broadcast después (RF-09).
 */
async function loadState() {
    try {
        const response = await fetch(endpoints.state, { headers: { Accept: 'application/json' } });

        if (! response.ok) {
            setConnection(`Error ${response.status}`, 'offline');

            return;
        }

        render(await response.json());
    } catch {
        setConnection('Sin conexión', 'offline');
    }
}

function wireDevBroadcast() {
    const button = find('[data-dev-broadcast]');

    if (! button) {
        return;
    }

    let version = 0;

    button.addEventListener('click', async () => {
        version += 1;
        button.disabled = true;

        try {
            await postJson(endpoints.devBroadcast, { version, summary: `Evento de prueba #${version}` });
        } catch (error) {
            setConnection(error.message, 'offline');
        } finally {
            button.disabled = false;
        }
    });
}

wireForm('[data-join]', {
    url: endpoints.join,
    payload: (data) => ({ name: String(data.get('name') ?? '').trim() }),
    reload: true,
});

wireForm('[data-host-login]', {
    url: endpoints.hostLogin,
    payload: (data) => ({ pin: String(data.get('pin') ?? '') }),
    reload: true,
});

wireForm('[data-open-lot]', {
    url: endpoints.openLot,
    payload: (data) => ({
        title: String(data.get('title') ?? '').trim(),
        description: String(data.get('description') ?? '').trim() || null,
        start_price_cents: numberField(data, 'start_price_cents'),
        min_increment_cents: numberField(data, 'min_increment_cents'),
        duration_seconds: numberField(data, 'duration_seconds'),
    }),
});

watchConnection();
subscribe();
wireDevBroadcast();
wireBidForm();
wireCloseLot();
setInterval(renderCountdown, 250);
loadState();
