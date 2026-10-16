# Changelog

Todo notable de este proyecto se documenta aquí, siguiendo [Keep a Changelog](https://keepachangelog.com/) y [SemVer](https://semver.org/).

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
