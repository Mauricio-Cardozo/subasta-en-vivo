# Changelog

Todo notable de este proyecto se documenta aquí, siguiendo [Keep a Changelog](https://keepachangelog.com/) y [SemVer](https://semver.org/).

## [Sin publicar]

### Agregado

- Soporte para probar con un túnel (cloudflared, ngrok): `config/trustedproxy.php` lee `TRUSTED_PROXIES` para confiar en las cabeceras `X-Forwarded-*` del agente local. Sin eso, detrás de un túnel HTTPS los assets salían con `http://` (bloqueados por contenido mixto) y todos los visitantes compartían la IP `127.0.0.1` en los límites por IP. README: sección **Probar con un túnel**.

## [1.0.0] - 2026-10-17

Hito 7: pulido y entrega.

### Agregado

- Historial de lotes cerrados: `AuctionSnapshot::history()` (RF-13) devuelve los lotes `sold`/`unsold`/`cancelled` del más nuevo al más viejo, acotados por `auction.history.limit`, con título, estado, precio final, nombre del ganador y cierre — **sin ids internos**. Viaja dentro del snapshot público, así que está al cargar por `GET /auction/state`, al reconectar y en cada `AuctionStateUpdated`: no hay endpoint nuevo.
- Parcial `partials/history.blade.php`, compartido por las dos pantallas, y dibujo en `auction.js` (estado coloreado, precio final y ganador; estado vacío cuando no se cerró ninguno).

### Cambiado

- El indicador de conexión del encabezado arranca en `Conectando…` (antes `Sin conectar`), tiene color por estado (verde `En vivo`, ámbar `Conectando…`/`Reconectando…`, rojo `Sin conexión`) y es una región viva (`role="status"` + `aria-live="polite"`). Sigue siendo informativo: las acciones van por HTTP y al reconectar se re-sincroniza el estado (y el historial del chat).
- README final: secciones de **Historial de lotes** y estados de conexión, guía **Cómo probarlo** con dos navegadores y repaso de la entrega.

### Notas

- La entrega cubre el flujo completo: identidad, apertura de lotes, pujas concurrentes, cierre por vencimiento y manual, anti-sniping, chat con moderación, historial, observabilidad y auditoría.
- Todo el comportamiento crítico está probado con tests (unidad, feature, vistas, guardas del cliente y concurrencia real en PostgreSQL) y verificado a mano con Reverb.

## [0.6.0] - 2026-10-16

Hito 6: logging y trazabilidad.

### Agregado

- Canales de log en JSON: `auction` (aplicación) y `audit` (auditoría) con `RotatingFileHandler` + `JsonFormatter` — una línea JSON por registro — y rotación diaria con retención `LOG_DAILY_DAYS`. En `.env.example`, `LOG_CHANNEL=auction`.
- `App\Support\CorrelationId` (singleton) y middleware global `AssignCorrelationId`: toma `X-Correlation-ID` si trae un valor seguro (`[A-Za-z0-9._-]`, ≤64) o genera un UUID, lo comparte con todos los registros (`Log::shareContext`) y lo devuelve en la cabecera de la respuesta, para atar un reporte del navegador con su rastro en los logs.
- `App\Services\AuditLogger::record($event, $context)` y `App\Support\RequestContext` (IP de la petición): cada acción que cambia estado —o que se intenta— deja un registro en el canal `audit` con actor (`actor_key`), IP, `action_id`, resultado (`accepted`/`rejected`/`replayed`/`in_progress`) y `correlation_id`.
- Acciones auditadas: alta de pujador (`identity.joined`), login del subastador (éxito `host.login` y fallo `host.login_failed`, **sin registrar el PIN**), apertura (`auction.lot_open`) y cierre (`auction.lot_close`) de lote, puja (`auction.bid`) y chat (`chat.posted`, `chat.deleted`, `chat.post_denied`, `chat.delete_denied`).
- `ActionIdempotency::run(..., ?string $event)` audita el resultado una sola vez por camino: un reintento con el mismo `action_id` se marca `replayed` y no se cuenta como acción nueva.
- `config/auction.php`: `audit.channel` (por defecto `audit`).
- Tests: `CorrelationIdTest` (cabecera de respuesta, valor entrante seguro/inseguro, contexto compartido), `JsonLogChannelTest` (canales JSON con rotación y `correlation_id` en el contexto) y `AuditLogTest` (alta, login éxito/fallo sin el PIN, puja aceptada/rechazada/repetida, apertura y cierre de lote, chat publicar/borrar/denegar y `correlation_id` por petición).

### Notas

- El `correlation_id` vive una petición: el middleware lo fija al empezar; en consola, `CorrelationId::get()` genera uno si nadie lo fijó.
- Verificación manual (servidor local): `POST /join` con `X-Correlation-ID: manual-456` responde con esa cabecera y deja en `storage/logs/audit-*.log` la línea JSON `identity.joined` con `correlation_id`, `actor_key` e `ip`.

## [0.5.0] - 2026-10-16

Hito 5: chat de la sala.

### Agregado

- Esquema del chat: `chat_messages` (sala, autor opcional, copia del nombre y texto) con índice `(room_id, id)`; modelo `ChatMessage` con su factory y relaciones `AuctionRoom::chatMessages()` / `Participant::chatMessages()`.
- `POST /auction/chat` (límite `chat-messages` por quien escribe): publican el pujador y el subastador. El servidor decide autor y firma (el subastador se firma con `chat.host_name`, que comparte literal con el nombre reservado); `ChatMessageRequest` recorta el texto y aplica el tope `chat.max_length`.
- `App\Services\ChatService::post()`: persiste en una transacción y emite `ChatMessagePosted` (canal público `auction`) **después del commit**, con solo `id`, `author_name`, `body` y `posted_at`.
- `GET /auction/chat` (público, `no-store`): los últimos `chat.history` mensajes en orden de llegada; es la fuente de verdad al cargar y al reconectar, porque Reverb no repite lo que pasó sin conexión.
- `DELETE /auction/chat/{message}`: borra el autor o el subastador (moderación); físico, con `ChatMessageDeleted` tras el commit. Otro rol recibe 403 y un mensaje inexistente, 404.
- UI del chat compartida por ambas pantallas (`partials/chat.blade.php` + `resources/js/chat.js`): historial por HTTP y mensajes en vivo, orden por `id`, render con `textContent`, botón **Borrar** para el autor y para el subastador, y errores del servidor en el propio panel.
- Tests: `ChatSchemaTest`, `ChatEndpointTest` (identidad, publicación, evento, recorte, validación y límite), `ChatModerationTest` (historial, truncado, autor/subastador/otro/invitado, 404) y concurrencia del chat contra PostgreSQL (publicaciones concurrentes sin pérdida y en orden, doble borrado sin romper, borrado + publicación coherentes). Guardas del cliente para `chat.js`.

### Notas

- El chat **no** usa `version` ni `action_id`: no hay estado compartido que ordenar (se ordena por `id`) y publicar no necesita idempotencia (el texto lo elige la persona). La moderación es un borrado físico, así que al recargar no vuelve.
- Verificación manual con dos navegadores (Reverb real): el pujador y el subastador publican y se ven en vivo sin recargar; el subastador ve el botón de borrar en todos los mensajes y el pujador solo en los propios; el borrado desaparece en ambas pantallas al instante y no vuelve tras recargar.

## [0.4.0] - 2026-10-16

Hito 4: temporizador, cierre y anti-sniping.

### Agregado

- Cierre del lote con adjudicación y descuento de saldo: `AuctionService::closeCurrentLot()` (manual) y `closeLotIfDue()` (perezoso por vencimiento) comparten `settle()`, que decide ganador, descuenta el saldo una sola vez y sube la `version` una sola vez. Sin pujas, el lote queda `unsold`.
- Anti-sniping (`applyAntiSniping()`): una puja aceptada dentro de `window_seconds` del cierre corre `ends_at` `extension_seconds`, con tope `max_extensions`; la extensión y la puja son una sola transición de versión.
- `POST /auction/lots/close` (solo subastador, middleware `host` y límite `lot-closes`): cierra el lote abierto y devuelve el resultado (estado, ganador, monto, versión); es idempotente por `action_id`.
- `App\Services\ActionIdempotency`: guard de idempotencia `(actor_key, action_id)` extraído de `BidController` y compartido por las pujas y el cierre (201 éxito / 422 rechazo / 409 acción en curso; un error inesperado libera la reclamación).
- La `actor_key` del subastador pasa a ser un token durable de la sesión (`host_token`). Antes se derivaba del id de sesión, que se regenera (login, invalidaciones), así que su idempotencia no podía funcionar de verdad.
- UI: temporizador `m:ss` en el panel calculado con el `remaining_ms` del servidor (se reancla con cada estado), estado traducido (`Abierto`, `Vendido`, `Sin vender`, `Cancelado`), fila del ganador y botón **Cerrar lote** para el subastador.
- Tests de concurrencia del cierre contra PostgreSQL: dos cierres simultáneos adjudican una sola vez, un cierre contra una puja deja un lote coherente en cualquier orden y tres pujas al borde extienden el cierre sin perderse ni pasarse del tope.

### Cambiado

- `placeBid()` ahora cierra perezosamente el lote vencido antes de evaluar la puja; un lote no abierto responde `lot_expired` si el plazo pasó o `lot_closed` si lo cerró el subastador. Se eliminó el chequeo de vencimiento independiente que quedaba inalcanzable.
- `BidController` dejó de contener la lógica de idempotencia: usa `ActionIdempotency`.

### Notas

- `GET /auction/state` mantiene el cierre perezoso con una comprobación barata previa (sin `lockForUpdate` en cada lectura); solo abre transacción si de verdad hay un lote vencido.
- Verificación manual con dos navegadores (Reverb real): el temporizador baja en pantalla, el lote vence solo y queda `Sin vender`/`Vendido` con el saldo descontado, cambia en vivo por el anti-sniping y el subastador puede cerrar a mano.

## [0.3.0] - 2026-10-16

Hito 3: pujas y concurrencia.

### Agregado

- Esquema de pujas: `bids` y `auction_actions`, con la restricción única `(actor_key, action_id)` que sostiene la idempotencia; modelos `Bid` y `AuctionAction` con sus factories.
- `App\Services\AuctionService::placeBid()`: transacción + `lockForUpdate` sobre la sala y el lote, puja mínima calculada por el servidor (igual al precio inicial en la primera puja y con el incremento después), saldo comprobado sin descontar, rechazo de la auto-puja y de pujas fuera de tiempo; `current_price_cents`, `leader_participant_id` y `version` cambian en la misma transacción.
- `App\Support\Money`: formatea centavos sin aritmética de punto flotante.
- `POST /auction/bids`: exige identidad (middleware `participant`), limita las pujas **por pujador** (`bids_per_second`) y es idempotente por `action_id`: reintentar devuelve la misma respuesta sin volver a pujar, incluso si fue un rechazo.
- El bloque `bids` del snapshot público trae las últimas pujas del lote abierto (tope en `config/auction.php`), de la más nueva a la más vieja, con autor, monto y momento, sin ningún id interno.
- UI del pujador: botón **Pujar** con la puja mínima que aplicaría el servidor (el cliente no manda monto), aviso **Ya vas ganando este lote**, lista de pujas en vivo y mensajes de error del servidor en el propio formulario.
- Tests de concurrencia real (`tests/Feature/ConcurrencyTest.php`) contra PostgreSQL en la CI: el bloqueo serializa dos conexiones, cuatro pujas simultáneas no pierden actualizaciones, dos pujas del mismo monto dejan un solo ganador y el `action_id` duplicado concurrente se registra una sola vez.

### Cambiado

- La `actor_key` de la idempotencia es el participante (`participant:{id}`) y no el hash del id de sesión: el id de sesión se regenera al cambiar de identidad, y el participante es la identidad durable. `participants.session_id` sigue guardando el hash de la sesión que ata el navegador al participante.
- La CI levanta un servicio PostgreSQL y corre los tests de concurrencia contra él; en SQLite esos tests se saltan con un motivo explícito.

### Notas

- La verificación manual con dos navegadores encontró dos bugs de UI que este hito corrige: el liderazgo se deduce del estado público del lote (el broadcast no lleva `me`) y el error de puja se limpia al cambiar de lote.

## [0.2.0] - 2026-10-16

Hito 2: sala, lotes y estado compartido.

### Agregado

- Esquema base: `auction_rooms`, `lots` y `participants`, con FK circular resuelta en una migración aparte y `AuctionRoomSeeder` idempotente.
- `App\Enums\LotStatus` y modelos `AuctionRoom`, `Lot`, `Participant` con sus factories.
- `config/auction.php`: saldo inicial, incremento mínimo, duración, ventana y tope de anti-sniping, límites de peticiones, chat y snapshot.
- Identidad de sesión: `POST /join` (nombre validado, reservado y único sin distinguir mayúsculas) y `POST /host/login` con el PIN de `AUCTION_HOST_PIN` comparado en tiempo constante.
- `App\Services\SessionIdentity`: `session_id` guardado como hash del id de sesión (futura `actor_key`); cambiar de identidad invalida la sesión.
- Middleware `participant` y `host` con sus alias; límites de peticiones por IP desde config (`join`, `host-login`, `lot-opens`).
- `App\Support\ActionResult`: contrato de las acciones del servidor; un rechazo de negocio devuelve 422 con motivo, sin excepción ni evento.
- `App\Services\AuctionService::openLot()`: transacción + `lockForUpdate` sobre la sala, un solo lote abierto, `current_lot_id`/`status` consistentes y `version` que sube una vez.
- `GET /auction/state` con `Cache-Control: no-store` y bloque `me` (saldo propio, rol); `App\Services\AuctionSnapshot` es el serializador compartido con el evento.
- UI: alta de pujador, login del subastador, formulario de apertura de lote, panel del lote y saldo propio; ambas pantallas cargan el estado por HTTP al abrir y se actualizan en vivo sin recargar.
- Errores del servidor (422) mostrados en el formulario; el cliente nunca simula éxito.

### Notas

- `lockForUpdate` es un no-op en SQLite: la prueba de concurrencia contra un motor real llega en el Hito 3 con MySQL/PostgreSQL en la CI.
- Sin login de subastador configurado (PIN vacío) el acceso siempre falla.

## [0.1.0] - 2026-10-15

Hito 1: dos pantallas conectadas.

### Agregado

- Estructura inicial: Laravel 12, Reverb, Laravel Echo, Vite, Pint y CI con GitHub Actions.
- README con instalación, arquitectura y reglas de oro.
- Plantillas de issue y PR; licencia MIT.
- Pantalla de entrada `/` con selector de rol, y pantallas `/emisor` (subastador) y `/receptor` (pujador) sobre un layout Blade compartido.
- `App\Events\AuctionStateUpdated`: evento del canal público `auction`, que se emite **después del commit** (`ShouldDispatchAfterCommit`) y filtra el payload a claves públicas.
- `resources/js/auction.js`: Echo escuchando el canal `auction`, render con `textContent` e ignorado de eventos con `version` vieja o duplicada.
- Indicador de conexión en vivo basado en el estado de Reverb.
- `POST /dev/broadcast` y botón "Emitir evento de prueba", disponibles solo en `APP_ENV=local`.
- Guardas de test: sin `innerHTML`/`outerHTML`/`document.write` en el cliente, un único listener por página y el emisor de prueba oculto fuera de local.

### Cambiado

- El entry de Vite pasa de `app.js` a `auction.js`; se eliminan `app.js` y `bootstrap.js` del scaffold.
- `APP_NAME="Subasta en Vivo"` en `.env.example`.
