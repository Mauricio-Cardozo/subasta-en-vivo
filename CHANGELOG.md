# Changelog

Todo notable de este proyecto se documenta aquí, siguiendo [Keep a Changelog](https://keepachangelog.com/) y [SemVer](https://semver.org/).

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

## [Sin publicar]
