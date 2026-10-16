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

La CI de GitHub Actions corre ambos (más `npm run build`) en cada PR y bloquea el merge en `main` si fallan. Además levanta un PostgreSQL y corre ahí los tests de concurrencia (`--filter=ConcurrencyTest`), que en SQLite se saltan porque ese motor no bloquea filas.

```bash
# Concurrencia real (opcional, en local):
docker run -d --name auction-pg -p 5432:5432 \
  -e POSTGRES_USER=auction -e POSTGRES_PASSWORD=secret -e POSTGRES_DB=auction_test postgres:16
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 \
DB_DATABASE=auction_test DB_USERNAME=auction DB_PASSWORD=secret \
php artisan test --filter=ConcurrencyTest
```

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
        │  POST /auction/lots/close (subastador)│  closeCurrentLot + lockForUpdate│
        │  POST /auction/bids (solo pujador) ──►│  placeBid + lockForUpdate     │
        │  POST /auction/chat (pujador o subastador)│  ChatService::post        │
        │  GET /auction/chat (historial) ──────►│  ChatService::history         │
        │  DELETE /auction/chat/{id} (moderar) ►│  ChatService::delete          │
```

- `window.Echo.channel('auction').listen('.AuctionStateUpdated', …)` — un único listener por página (`resources/js/auction.js`).
- El chat usa el mismo canal desde `resources/js/chat.js`: `.ChatMessagePosted` y `.ChatMessageDeleted`, y pide `GET /auction/chat` al cargar y al reconectar.
- El estado inicial se pide por **HTTP** (`GET /auction/state`); al reconectar también. Reverb no reemplaza al endpoint.
- El cliente ignora eventos con `version` menor o igual a la mostrada.
- El indicador del encabezado refleja el estado real de la conexión (`En vivo`, `Conectando…`, `Reconectando…`, `Sin conexión`).
- En `APP_ENV=local` aparece el botón **Emitir evento de prueba**, que llama a `POST /dev/broadcast` (ruta que no existe en otros entornos).

### Pujar

- El pujador manda **solo un `action_id`** (`POST /auction/bids`); el monto efectivo lo calcula el servidor con el estado que tiene bajo bloqueo. El botón muestra la puja mínima que saldría del servidor, pero esa cuenta es solo informativa.
- Reintentar con el mismo `action_id` devuelve la respuesta original (misma puja, mismo rechazo): la acción no se aplica dos veces.
- Un 422 del servidor se muestra **tal cual** en el formulario; nunca se pinta como éxito.

### Cerrar el lote

- El subastador cierra a mano con `POST /auction/lots/close` (solo con lote abierto, idempotente por `action_id` igual que las pujas). Comparte el desenlace con el vencimiento: con pujas queda `sold` y se descuenta el saldo; sin pujas, `unsold`.
- El **temporizador** del panel se calcula con el `remaining_ms` del servidor (no con el reloj del navegador) y se reancla con cada estado; al llegar a cero pide el estado para que el servidor liquide el lote. El navegador nunca simula el cierre.
- Una puja dentro de la **ventana final** corre el cierre (anti-sniping, con tope de extensiones); el panel lo refleja en el resumen del evento y en el contador reajustado.

### Chat

- Publican el **pujador** y el **subastador** (`POST /auction/chat`). El servidor decide autor y firma: el subastador se firma con `chat.host_name` porque no tiene fila de participante. El texto se recorta y se corta en `chat.max_length`.
- En vivo por el mismo canal `auction`: `ChatMessagePosted` para lo nuevo y `ChatMessageDeleted` para lo borrado. El cliente pide `GET /auction/chat` al cargar y **al reconectar** (Reverb no repite lo que pasó sin conexión) y ordena por `id`.
- **Moderar:** el subastador borra cualquier mensaje; el pujador, solo los suyos. El servidor lo vuelve a comprobar (el botón del cliente es solo cosmético) y el borrado es físico, así que no reaparece al recargar.

## Identidad

No hay cuentas ni contraseñas: la identidad vive en la **sesión del servidor**.

- **Pujador:** `POST /join` con un nombre validado (longitud, caracteres, reservado `Subastador` y único sin distinguir mayúsculas) guarda un `participants` ligado a la sesión y le asigna el saldo virtual.
- **Subastador:** `POST /host/login` compara el PIN de `AUCTION_HOST_PIN` en tiempo constante. Sin PIN configurado el acceso **siempre** falla. El rol queda en la sesión, nunca en el navegador.
- El vínculo navegador↔participante se guarda hasheado (`sha256(id de sesión | app key)`) en `participants.session_id` y nunca viaja al cliente.
- La clave de idempotencia (`actor_key`) es el **participante** (`participant:{id}`), no el hash de la sesión: el id de sesión se regenera al cambiar de identidad y el participante es la identidad durable. Para el subastador, que no tiene fila de participante, se usa un token durable de la sesión (`host:{token}`).
- Al cambiar de identidad (unirse o loguearse como subastador) la sesión se invalida: el navegador no arrastra privilegios del rol anterior.
- Los middleware `participant` y `host` protegen las acciones; el servidor responde 403 sin el rol.

## Arquitectura (resumen)

- Toda acción que modifica estado es atómica: transacción + `lockForUpdate` sobre la fila de la sala (`auction_rooms`), con un único orden de bloqueo.
- El servidor valida todo: montos, saldo, tiempo y rol. Ningún valor económico lo decide el cliente; los montos son enteros en centavos. Las acciones devuelven un `ActionResult`: un rechazo de negocio es un 422 con motivo, nunca una excepción ni un evento.
- `AuctionSnapshot` es el serializador único del estado público: lo comparten el evento y `GET /auction/state`, y solo expone nombres y montos (nunca ids internos, sesiones ni saldos ajenos). Incluye las últimas pujas del lote abierto.
- El broadcast (`AuctionStateUpdated`, canal público `auction`) se emite solo después del `commit`. Un rechazo no emite evento de éxito: responde 422 y se audita.
- Cierre perezoso sin cron: el vencimiento se resuelve dentro de cada acción y de `GET /auction/state` (con `Cache-Control: no-store` e idempotente); el subastador puede cerrar antes con `POST /auction/lots/close`.
- `version` es la versión del estado persistido de la sala: sube una vez por transición confirmada y permite ignorar eventos desordenados. El chat no la usa: se ordena por `id`.
- Balance y reglas en `config/auction.php` (incluidos los límites de peticiones); la lógica de subasta vive en `app/Services/AuctionService.php`; los controladores son delgados.
- Concurrencia real probada contra PostgreSQL en la CI (`tests/Feature/ConcurrencyTest.php`): sin `lockForUpdate` dos pujas simultáneas perderían una actualización, dos cierres adjudicarían dos veces o una puja y un cierre dejarían el lote incoherente.
- Idempotencia por `action_id` con restricción única `(actor_key, action_id)`; reintentar repite la respuesta original. El guard vive en `App\Services\ActionIdempotency` y lo comparten las pujas y el cierre; la `actor_key` del subastador es un token durable de la sesión, no el id de sesión (que se regenera).

## Observabilidad

- **Logs en JSON:** los canales `auction` (aplicación) y `audit` (auditoría) escriben una línea JSON por registro en `storage/logs/` y rotan a diario, conservando `LOG_DAILY_DAYS` archivos. En `.env.example`, `LOG_CHANNEL=auction`.
- **`correlation_id` de punta a punta:** el middleware `AssignCorrelationId` toma `X-Correlation-ID` si trae un valor seguro (o genera un UUID), lo comparte con **todos** los registros (`Log::shareContext`) y lo devuelve en la cabecera de la respuesta. Un problema reportado con esa cabecera se encuentra en los logs sin adivinar.
- **Auditoría:** cada acción que cambia estado —o que se intenta— deja un registro en el canal `audit` con el actor (`actor_key`), la IP, el `action_id`, el resultado (`accepted`/`rejected`/`replayed`/`in_progress`) y el `correlation_id`. Incluye el login del subastador (el **PIN nunca se registra**) y las denegaciones del chat. El guard de idempotencia audita una sola vez por camino, así un reintento no se cuenta como una acción nueva.
- El valor entrante de `X-Correlation-ID` se valida (`[A-Za-z0-9._-]`, ≤64) para no inyectar en los registros.

## Estructura

```
app/
  Events/            # AuctionStateUpdated, ChatMessagePosted, ChatMessageDeleted
  Http/Controllers/  # AuctionController, ParticipantController, HostAuthController, BidController, ChatController (delgados)
  Http/Middleware/   # EnsureHost, EnsureParticipant, AssignCorrelationId
  Http/Requests/     # JoinRequest, HostLoginRequest, OpenLotRequest, CloseLotRequest, PlaceBidRequest, ChatMessageRequest
  Models/            # AuctionRoom, Lot, Participant, Bid, AuctionAction, ChatMessage
  Services/          # AuctionService, AuctionSnapshot, ChatService, SessionIdentity, ActionIdempotency, AuditLogger
  Support/           # ActionResult, Money, CorrelationId, RequestContext
config/auction.php
resources/views/auction/   # layout, emisor, receptor, partials/lot, partials/chat
resources/js/              # auction.js, chat.js
tests/Feature/, tests/Unit/   # ConcurrencyTest solo corre con PostgreSQL/MySQL
```

## Hitos

- [x] **Hito 0 — Preparación:** repo, Laravel 12 + Reverb + Echo + Vite, Pint, CI.
- [x] **Hito 1 — Dos pantallas conectadas:** `/emisor` y `/receptor` con un evento de prueba.
- [x] **Hito 2 — Sala, lotes y estado compartido:** esquema y balance, identidad (alta + PIN), apertura de lote bajo bloqueo y estado público por HTTP.
- [x] **Hito 3 — Pujas y concurrencia:** esquema de pujas e idempotencia, `placeBid` bajo bloqueo, `POST /auction/bids`, pujas en el snapshot, UI de puja y tests de concurrencia real contra PostgreSQL.
- [x] **Hito 4 — Temporizador, cierre y anti-sniping:** cierre por vencimiento con adjudicación y descuento de saldo, anti-sniping, cierre manual del subastador idempotente, temporizador y resultado en las pantallas, y tests de concurrencia del cierre.
- [x] **Hito 5 — Chat:** esquema de mensajes; `POST /auction/chat` para pujador y subastador con `ChatMessagePosted` tras el commit; historial por `GET /auction/chat` y moderación por `DELETE` con `ChatMessageDeleted`; panel compartido en vivo (historial por HTTP al cargar y al reconectar) y tests de concurrencia del chat contra PostgreSQL.
- [x] **Hito 6 — Logging y trazabilidad:** canales `auction`/`audit` en JSON con rotación diaria, `correlation_id` de punta a punta (`X-Correlation-ID` en la respuesta) y auditoría de acciones con el `action_id` y el resultado.
- [ ] **Hito 7 — Pulido y entrega.**

Cada hito es un milestone en GitHub; cada tarea, un issue; cada issue, una rama y un PR.

## Git

- GitHub Flow: `main` siempre estable (PR obligatorio, CI verde); una rama por issue (`feat/12-place-bid-endpoint`).
- Commits atómicos con [Conventional Commits](https://www.conventionalcommits.org/).
- PRs chicos (ideal <300 líneas), con `Closes #n` para cerrar el issue al mergear.
- Tags semánticos por hito (`v0.1.0`, `v0.2.0`, `v0.3.0`, …) con su resumen en [CHANGELOG.md](CHANGELOG.md).

## Licencia

[MIT](LICENSE)
