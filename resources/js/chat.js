import './echo';
import { newActionId } from './action-id';

/**
 * Chat de la sala (RF-11, RF-12).
 *
 * Mismo criterio que el estado de la subasta: HTTP primero (el historial) y el
 * canal `auction` para lo que pasa en vivo. Reverb no reemplaza al endpoint, así
 * que al reconectar se vuelve a pedir el historial por HTTP.
 *
 * Reglas del cliente:
 *  - Todo texto se pinta con `textContent`, nunca con `innerHTML`.
 *  - El historial y la publicación solo dicen `body`: el autor y el rol los
 *    decide el servidor.
 *  - El navegador no decide permisos: solo dibuja el botón de borrar según la
 *    marca `data-moderate` y el nombre; el servidor vuelve a comprobar.
 */
const endpoints = {
    chat: '/auction/chat',
};

const selectors = {
    panel: '[data-chat]',
    list: '[data-chat-messages]',
    empty: '[data-chat-empty]',
    form: '[data-chat-form]',
    field: '[data-chat-field]',
    error: '[data-chat-error]',
    message: '[data-chat-message]',
    delete: '[data-chat-delete]',
};

/** Ids ya dibujados: el evento y la respuesta del POST no duplican la fila. */
const rendered = new Set();

function find(selector) {
    return document.querySelector(selector);
}

/** Nombre del pujador de esta sesión (el `[data-viewer-name]` del layout). */
function viewerName() {
    const element = find('[data-viewer-name]');

    return element ? element.textContent.trim() : null;
}

function isHost() {
    return find(selectors.panel)?.dataset.moderate === '1';
}

/**
 * ¿Esta sesión puede borrar el mensaje? El subastador, cualquiera; el pujador,
 * solo los suyos (los nombres son únicos en la sala y "Subastador" está
 * reservado, así que comparar el nombre alcanza para mostrarlo).
 */
function canDelete(message) {
    if (isHost()) {
        return true;
    }

    const name = viewerName();

    return name !== null && message.author_name === name;
}

function formatTime(iso) {
    if (! iso) {
        return '—';
    }

    const date = new Date(iso);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleTimeString('es-AR');
}

function csrfToken() {
    return find('meta[name="csrf-token"]')?.content ?? '';
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

async function request(url, options) {
    const response = await fetch(url, {
        ...options,
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            ...(options?.headers ?? {}),
        },
    });

    const payload = await response.json().catch(() => ({}));

    if (! response.ok) {
        throw new Error(firstErrorMessage(payload) ?? `El servidor respondió ${response.status}.`);
    }

    return payload;
}

function showError(message) {
    const box = find(selectors.error);

    if (box) {
        box.textContent = message;
        box.hidden = false;
    }
}

function clearError() {
    const box = find(selectors.error);

    if (box) {
        box.textContent = '';
        box.hidden = true;
    }
}

function toggleEmpty() {
    const list = find(selectors.list);
    const empty = find(selectors.empty);

    if (! list || ! empty) {
        return;
    }

    empty.hidden = list.querySelectorAll(selectors.message).length > 0;
}

/** Fila de un mensaje. Todo texto va con `textContent`. */
function buildRow(message) {
    const row = document.createElement('li');
    row.className = 'flex items-start justify-between gap-3 rounded-md border border-slate-800 bg-slate-950/40 px-3 py-2';
    row.dataset.chatMessage = '';
    row.dataset.messageId = String(message.id);

    const text = document.createElement('div');
    text.className = 'min-w-0';

    const author = document.createElement('span');
    author.className = 'font-medium text-amber-300';
    author.textContent = message.author_name ?? '—';

    const time = document.createElement('span');
    time.className = 'ml-2 text-xs text-slate-600';
    time.textContent = formatTime(message.posted_at);

    const body = document.createElement('p');
    body.className = 'mt-0.5 break-words text-slate-200';
    body.textContent = message.body ?? '';

    text.append(author, time, body);
    row.append(text);

    if (canDelete(message)) {
        const button = document.createElement('button');
        button.type = 'button';
        button.dataset.chatDelete = '';
        button.dataset.messageId = String(message.id);
        button.className = 'shrink-0 rounded-md border border-slate-700 px-2 py-1 text-xs text-slate-400 transition hover:border-rose-600 hover:text-rose-300 disabled:opacity-50';
        button.textContent = 'Borrar';
        row.append(button);
    }

    return row;
}

/** Inserta un mensaje en orden de `id` (los eventos pueden llegar desordenados). */
function insert(message) {
    const list = find(selectors.list);

    if (! list || ! message || rendered.has(message.id)) {
        return;
    }

    rendered.add(message.id);

    const row = buildRow(message);
    const next = [...list.querySelectorAll(selectors.message)]
        .find((element) => Number(element.dataset.messageId) > message.id);

    if (next) {
        list.insertBefore(row, next);
    } else {
        list.append(row);
    }

    toggleEmpty();
}

function remove(messageId) {
    rendered.delete(messageId);

    const list = find(selectors.list);
    const row = list?.querySelector(`[data-chat-message][data-message-id="${Number(messageId)}"]`);

    row?.remove();
    toggleEmpty();
}

function renderHistory(messages) {
    const list = find(selectors.list);

    if (! list) {
        return;
    }

    while (list.firstChild) {
        list.removeChild(list.firstChild);
    }

    rendered.clear();

    (Array.isArray(messages) ? messages : []).forEach((message) => {
        rendered.add(message.id);
        list.append(buildRow(message));
    });

    toggleEmpty();
}

async function loadChat() {
    try {
        const payload = await request(endpoints.chat);

        renderHistory(payload.messages ?? []);
    } catch {
        // Sin conexión: el historial se reintenta al reconectar.
    }
}

function wireForm() {
    const form = find(selectors.form);

    if (! form) {
        return;
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearError();

        const field = form.querySelector(selectors.field);
        const body = String(field?.value ?? '').trim();

        if (body === '') {
            return;
        }

        const button = form.querySelector('button[type="submit"]');

        if (button) {
            button.disabled = true;
        }

        try {
            // El servidor decide autor y momento; la fila vuelve por la respuesta
            // (y por el evento, que `rendered` evita duplicar).
            const message = await request(endpoints.chat, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                // Un id por envío: si la red reintenta, no se duplica (6).
                body: JSON.stringify({ body, action_id: newActionId() }),
            });

            field.value = '';
            insert(message);
        } catch (error) {
            showError(error.message);
        } finally {
            if (button) {
                button.disabled = false;
            }
        }
    });
}

function wireDelete() {
    const list = find(selectors.list);

    if (! list) {
        return;
    }

    list.addEventListener('click', async (event) => {
        const button = event.target.closest(selectors.delete);

        if (! button) {
            return;
        }

        clearError();
        button.disabled = true;

        const id = Number(button.dataset.messageId);

        try {
            await request(`${endpoints.chat}/${id}`, { method: 'DELETE' });
            // El evento quita la fila; si no llega (sin conexión), se quita igual.
            remove(id);
        } catch (error) {
            showError(error.message);
        } finally {
            button.disabled = false;
        }
    });
}

function subscribe() {
    if (! window.Echo) {
        return;
    }

    const channel = window.Echo.channel('auction');

    channel.listen('.ChatMessagePosted', (message) => insert(message));
    channel.listen('.ChatMessageDeleted', (payload) => remove(payload.id));
}

function watchConnection() {
    const connection = window.Echo?.connector?.pusher?.connection;

    if (! connection) {
        return;
    }

    // Reverb no reemplaza al endpoint: al reconectar se recarga el historial.
    connection.bind('state_change', ({ current }) => {
        if (current === 'connected') {
            loadChat();
        }
    });
}

wireForm();
wireDelete();
subscribe();
watchConnection();
loadChat();
