# Subasta en Vivo

[![CI](https://github.com/Mauricio-Cardozo/subasta-en-vivo/actions/workflows/ci.yml/badge.svg)](https://github.com/Mauricio-Cardozo/subasta-en-vivo/actions/workflows/ci.yml)

Subasta en vivo para varias personas con roles asimétricos sobre una **sala compartida**. Un subastador publica lotes y los pujadores compiten por ellos. Todos ven el precio, el temporizador y el chat en tiempo real.

| Rol | Pantalla | Qué hace |
|---|---|---|
| **Subastador** (emisor) | `/emisor` | Abre lotes (título, precio inicial, incremento mínimo, duración), cierra un lote antes de tiempo y modera el chat. |
| **Pujador** (receptor) | `/receptor` | Ve el lote activo, el precio actual y el temporizador; puja; ve su saldo; chatea. |

Los nombres describen el rol de cada pantalla, no una limitación técnica: ambos envían acciones y ambos reciben eventos.

**Reglas de oro:**

1. El servidor es la única autoridad. El navegador pide acciones y dibuja el estado que le llega.
2. Se emite broadcast **después** del commit, nunca dentro de la transacción.
3. Reverb no reemplaza al endpoint de estado: el estado se carga y se recupera por HTTP.

## Stack

- Laravel 12 · Reverb · Laravel Echo · Blade + JS
- MySQL/PostgreSQL en local y en CI (SQLite solo para arrancar)
- Logs JSON estructurados

## Instalación

```bash
composer install
npm ci
cp .env.example .env
php artisan key:generate
touch database/database.sqlite   # o usar MySQL/PostgreSQL configurando DB_* en .env
php artisan migrate --seed
npm run build                    # o npm run dev en desarrollo
```

Levantar los tres procesos:

```bash
php artisan serve        # app en http://localhost:8000
php artisan reverb:start # websocket en el puerto 8080
npm run dev              # assets en desarrollo
```

La configuración de Reverb (`REVERB_*` y `VITE_REVERB_*`) vive en `.env`; el repositorio solo incluye `.env.example` sin valores reales.

## Tests y calidad

```bash
./vendor/bin/pint --test   # formateo (Laravel Pint)
php artisan test           # tests
```

La CI de GitHub Actions corre ambos (más `npm run build`) en cada PR y bloquea el merge en `main` si fallan.

## Flujo en tiempo real

```
navegador (/emisor, /receptor)              servidor                        clientes
        │                                       │                               │
        │  GET /emisor | /receptor ────────────►│  Blade (según la sesión)      │
        │  GET /auction/state ─────────────────►│  AuctionSnapshot              │
        │◄────── estado inicial (no-store) ─────│                               │
        │  Echo: channel('auction')             │                               │
        │◄──────── WebSocket (Reverb :8080) ────│◄── AuctionStateUpdated ───────│
        │  dibuja snapshot (textContent)        │     (solo tras el commit)     │
        │  POST /join | /host/login             │                               │
        │  POST /auction/lots (solo subastador) │  AuctionService + lockForUpdate│
```

- `window.Echo.channel('auction').listen('.AuctionStateUpdated', …)` — un único listener por página (`resources/js/auction.js`).
- El estado inicial se pide por **HTTP** (`GET /auction/state`); al reconectar también. Reverb no reemplaza al endpoint.
- El cliente ignora eventos con `version` menor o igual a la mostrada.
- El indicador del encabezado refleja el estado real de la conexión (`En vivo`, `Conectando…`, `Reconectando…`, `Sin conexión`).
- En `APP_ENV=local` aparece el botón **Emitir evento de prueba**, que llama a `POST /dev/broadcast` (ruta que no existe en otros entornos).

## Identidad

No hay cuentas ni contraseñas: la identidad vive en la **sesión del servidor**.

- **Pujador:** `POST /join` con un nombre validado (longitud, caracteres, reservado `Subastador` y único sin distinguir mayúsculas) guarda un `participants` ligado a la sesión y le asigna el saldo virtual.
- **Subastador:** `POST /host/login` compara el PIN de `AUCTION_HOST_PIN` en tiempo constante. Sin PIN configurado el acceso **siempre** falla. El rol queda en la sesión, nunca en el navegador.
- El vínculo navegador↔participante se guarda hasheado (`sha256(id de sesión | app key)`) en `participants.session_id`: es la futura `actor_key` de la idempotencia y nunca viaja al cliente.
- Al cambiar de identidad (unirse o loguearse como subastador) la sesión se invalida: el navegador no arrastra privilegios del rol anterior.
- Los middleware `participant` y `host` protegen las acciones; el servidor responde 403 sin el rol.

## Arquitectura (resumen)

- Toda acción que modifica estado es atómica: transacción + `lockForUpdate` sobre la fila de la sala (`auction_rooms`), con un único orden de bloqueo.
- El servidor valida todo: montos, saldo, tiempo y rol. Ningún valor económico lo decide el cliente; los montos son enteros en centavos. Las acciones devuelven un `ActionResult`: un rechazo de negocio es un 422 con motivo, nunca una excepción ni un evento.
- `AuctionSnapshot` es el serializador único del estado público: lo comparten el evento y `GET /auction/state`, y solo expone nombres y montos (nunca ids internos, sesiones ni saldos ajenos).
- El broadcast (`AuctionStateUpdated`, canal público `auction`) se emite solo después del `commit`. Un rechazo no emite evento de éxito: responde 422 y se audita.
- Cierre perezoso sin cron: el vencimiento se resuelve dentro de cada acción y de `GET /auction/state` (con `Cache-Control: no-store` e idempotente).
- `version` es la versión del estado persistido de la sala: sube una vez por transición confirmada y permite ignorar eventos desordenados. El chat no la usa: se ordena por `id`.
- Balance y reglas en `config/auction.php` (incluidos los límites de peticiones); la lógica de subasta vive en `app/Services/AuctionService.php`; los controladores son delgados.
- Anti-sniping con tope de extensiones; idempotencia por `action_id` con restricción única `(actor_key, action_id)`.

## Estructura

```
app/
  Events/            # AuctionStateUpdated, ChatMessagePosted, ChatMessageDeleted
  Http/Controllers/  # AuctionController, ParticipantController, HostAuthController (delgados)
  Http/Middleware/   # EnsureHost, EnsureParticipant
  Http/Requests/     # JoinRequest, HostLoginRequest, OpenLotRequest
  Models/            # AuctionRoom, Lot, Participant (+ Bid, ChatMessage en Hito 3/5)
  Services/          # AuctionService, AuctionSnapshot, SessionIdentity
  Support/           # ActionResult
config/auction.php
resources/views/auction/   # layout, emisor, receptor, partials/lot
resources/js/              # auction.js (+ chat.js en Hito 5)
tests/Feature/, tests/Unit/
```

## Hitos

- [x] **Hito 0 — Preparación:** repo, Laravel 12 + Reverb + Echo + Vite, Pint, CI.
- [x] **Hito 1 — Dos pantallas conectadas:** `/emisor` y `/receptor` con un evento de prueba.
- [x] **Hito 2 — Sala, lotes y estado compartido:** esquema y balance, identidad (alta + PIN), apertura de lote bajo bloqueo y estado público por HTTP.
- [ ] **Hito 3 — Pujas y concurrencia** (tests contra MySQL/PostgreSQL).
- [ ] **Hito 4 — Temporizador, cierre y anti-sniping.**
- [ ] **Hito 5 — Chat.**
- [ ] **Hito 6 — Logging y trazabilidad.**
- [ ] **Hito 7 — Pulido y entrega.**

Cada hito es un milestone en GitHub; cada tarea, un issue; cada issue, una rama y un PR.

## Git

- GitHub Flow: `main` siempre estable (PR obligatorio, CI verde); una rama por issue (`feat/12-place-bid-endpoint`).
- Commits atómicos con [Conventional Commits](https://www.conventionalcommits.org/).
- PRs chicos (ideal <300 líneas), con `Closes #n` para cerrar el issue al mergear.
- Tags semánticos por hito (`v0.1.0` tras el Hito 1) con su resumen en [CHANGELOG.md](CHANGELOG.md).

## Licencia

[MIT](LICENSE)
