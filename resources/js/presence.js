import './echo';

/**
 * Quién está en la sala: canal de presencia `auction.presence`.
 *
 * Es el tercer tipo de canal (público, privado, presencia): antes de
 * suscribirse, Echo pide permiso a `/broadcasting/auth`, que lo concede con la
 * identidad de la sesión. Reverb avisa quién está (`here`), quién entra
 * (`joining`) y quién sale (`leaving`).
 *
 * Reglas del cliente:
 *  - Solo se une quien tiene identidad (`data-presence-join`); el resto sigue
 *    la subasta por el canal público.
 *  - Todo texto se pinta con `textContent`, nunca con `innerHTML`.
 *  - Es informativo: ninguna acción depende de esta lista.
 */
const selectors = {
    panel: '[data-presence]',
    members: '[data-presence-members]',
    count: '[data-presence-count]',
};

/** Miembros por seudónimo: varias pestañas de la misma sesión son uno. */
const members = new Map();

function find(selector) {
    return document.querySelector(selector);
}

function render() {
    const list = find(selectors.members);

    if (! list) {
        return;
    }

    while (list.firstChild) {
        list.removeChild(list.firstChild);
    }

    // El subastador primero; después los pujadores por nombre.
    const sorted = [...members.values()].sort((a, b) => {
        if (a.role !== b.role) {
            return a.role === 'host' ? -1 : 1;
        }

        return String(a.name).localeCompare(String(b.name), 'es');
    });

    sorted.forEach((member) => {
        const item = document.createElement('li');

        item.className = member.role === 'host'
            ? 'rounded-full border border-amber-700 bg-amber-950/40 px-3 py-1 text-amber-200'
            : 'rounded-full border border-slate-700 bg-slate-950 px-3 py-1 text-slate-200';
        item.textContent = member.role === 'host' ? `${member.name} · subastador` : String(member.name);

        list.append(item);
    });

    const count = find(selectors.count);

    if (count) {
        count.textContent = members.size === 1 ? '1 conectado' : `${members.size} conectados`;
    }
}

function memberKey(member) {
    return String(member.id);
}

function join() {
    const panel = find(selectors.panel);

    if (! panel || panel.dataset.presenceJoin !== '1' || ! window.Echo) {
        return;
    }

    window.Echo.join('auction.presence')
        .here((list) => {
            members.clear();
            list.forEach((member) => members.set(memberKey(member), member));
            render();
        })
        .joining((member) => {
            members.set(memberKey(member), member);
            render();
        })
        .leaving((member) => {
            members.delete(memberKey(member));
            render();
        })
        .error(() => {
            const count = find(selectors.count);

            if (count) {
                count.textContent = 'No disponible';
            }
        });
}

join();
