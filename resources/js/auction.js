import './echo';

/**
 * Puente entre el canal `auction` y el DOM.
 *
 * El servidor es la única autoridad: acá no se calcula nada, solo se dibuja el
 * snapshot que llega en cada `AuctionStateUpdated` (app/Events/AuctionStateUpdated.php).
 * Todo texto de usuario se pinta con `textContent`, nunca con `innerHTML`.
 */
const state = {
    version: null,
};

const selectors = {
    status: '[data-connection-status]',
    dot: '[data-connection-dot]',
    lastEvent: '[data-last-event]',
    version: '[data-state-version]',
    lotTitle: '[data-lot-title]',
    lotPrice: '[data-lot-price]',
    lotLeader: '[data-lot-leader]',
};

function find(selector) {
    return document.querySelector(selector);
}

function setConnection(label, online) {
    const status = find(selectors.status);
    const dot = find(selectors.dot);

    if (status) {
        status.textContent = label;
    }

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
 * Formatea centavos como pesos solo al mostrar (RNF-03: el servidor manda enteros).
 */
function formatCents(cents) {
    return (cents / 100).toLocaleString('es-AR', {
        style: 'currency',
        currency: 'ARS',
    });
}

function render(snapshot) {
    if (typeof snapshot.version === 'number') {
        // Los eventos desordenados o repetidos no se aplican.
        if (state.version !== null && snapshot.version <= state.version) {
            return;
        }

        state.version = snapshot.version;

        const versionEl = find(selectors.version);
        if (versionEl) {
            versionEl.textContent = snapshot.version.toString();
        }
    }

    const lastEvent = find(selectors.lastEvent);
    if (lastEvent) {
        const time = new Date(snapshot.server_now ?? Date.now()).toLocaleTimeString();
        lastEvent.textContent = `${describe(snapshot.action)} · ${time}`;
        lastEvent.dataset.version = snapshot.version ?? '';
    }

    const lot = snapshot.lot;

    if (! lot) {
        return;
    }

    const title = find(selectors.lotTitle);
    if (title) {
        title.textContent = lot.title ?? 'Sin título';
    }

    const price = find(selectors.lotPrice);
    if (price && typeof lot.current_price_cents === 'number') {
        price.textContent = formatCents(lot.current_price_cents);
    }

    const leader = find(selectors.lotLeader);
    if (leader) {
        leader.textContent = lot.leader_name ?? 'sin pujas';
    }
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
    connection.bind('state_change', ({ current }) => paint(current));
}

function subscribe() {
    if (! window.Echo) {
        setConnection('Echo no disponible', false);

        return;
    }

    // Un único listener por página para el estado de la subasta.
    window.Echo.channel('auction').listen('.AuctionStateUpdated', (snapshot) => render(snapshot));
}

/**
 * Botón de prueba del entorno local: pide al servidor que emita un evento.
 */
function wireDevBroadcast() {
    const button = find('[data-dev-broadcast]');

    if (! button) {
        return;
    }

    let version = 0;

    button.addEventListener('click', async () => {
        version += 1;

        const token = find('meta[name="csrf-token"]')?.content ?? '';

        button.disabled = true;

        try {
            const response = await fetch('/dev/broadcast', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token,
                    Accept: 'application/json',
                },
                body: JSON.stringify({ version, summary: `Evento de prueba #${version}` }),
            });

            if (! response.ok) {
                setConnection(`Error ${response.status}`, false);
            }
        } finally {
            button.disabled = false;
        }
    });
}

watchConnection();
subscribe();
wireDevBroadcast();
