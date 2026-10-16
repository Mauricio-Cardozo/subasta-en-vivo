import './echo';

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
};

const endpoints = {
    state: '/auction/state',
    join: '/join',
    hostLogin: '/host/login',
    openLot: '/auction/lots',
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
    lotLeader: '[data-lot-leader]',
    lotEnds: '[data-lot-ends]',
    lotWinnerRow: '[data-lot-winner-row]',
    lotWinner: '[data-lot-winner]',
    balance: '[data-balance]',
    bids: '[data-bids]',
    bidsEmpty: '[data-bids-empty]',
};

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

function setConnection(label, online) {
    setText(selectors.status, label);

    const dot = find(selectors.dot);

    if (dot) {
        dot.classList.toggle('bg-emerald-400', online);
        dot.classList.toggle('bg-slate-600', ! online);
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

function renderLot(lot) {
    toggle(selectors.lotPanel, Boolean(lot));
    toggle(selectors.lotEmpty, ! lot);

    if (! lot) {
        return;
    }

    setText(selectors.lotTitle, lot.title ?? 'Sin título');
    setText(selectors.lotDescription, lot.description ?? '');
    setText(selectors.lotStatus, lot.status ?? '');

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

    // Las pujas llegan en el Hito 3.
    toggle(selectors.bidsEmpty, ! bids || bids.length === 0);
}

function render(snapshot) {
    if (typeof snapshot.version === 'number') {
        // Los eventos desordenados o repetidos no se aplican (RNF-04).
        if (state.version !== null && snapshot.version <= state.version) {
            return;
        }

        state.version = snapshot.version;
        setText(selectors.version, String(snapshot.version));
    }

    renderLot(snapshot.lot);
    renderBids(snapshot.bids);

    const lastEvent = find(selectors.lastEvent);

    if (lastEvent) {
        const time = formatTime(snapshot.server_now);
        lastEvent.textContent = `${describe(snapshot.action)} · ${time}`;
    }

    if (snapshot.me) {
        setText(selectors.balance, typeof snapshot.me.balance_cents === 'number'
            ? formatCents(snapshot.me.balance_cents)
            : '—');
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

function subscribe() {
    if (! window.Echo) {
        setConnection('Echo no disponible', false);

        return;
    }

    // Un único listener por página para el estado de la subasta.
    window.Echo.channel('auction').listen('.AuctionStateUpdated', (snapshot) => render(snapshot));
}

function watchConnection() {
    const connection = window.Echo?.connector?.pusher?.connection;

    if (! connection) {
        setConnection('Sin conexión', false);

        return;
    }

    const labels = {
        connected: 'En vivo',
        connecting: 'Conectando…',
        disconnected: 'Reconectando…',
        unavailable: 'Sin conexión',
        failed: 'Sin conexión',
    };

    const paint = (pusherState) => setConnection(labels[pusherState] ?? pusherState, pusherState === 'connected');

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
            setConnection(`Error ${response.status}`, false);

            return;
        }

        render(await response.json());
    } catch {
        setConnection('Sin conexión', false);
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
            setConnection(error.message, false);
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
loadState();
