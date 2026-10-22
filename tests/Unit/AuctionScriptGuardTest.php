<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Guardas de seguridad del frontend (RNF-07).
 *
 * El texto de usuario nunca debe interpretarse como HTML: si alguien agrega
 * `innerHTML` al cliente, esta prueba falla antes que el XSS llegue a main.
 */
class AuctionScriptGuardTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function scripts(): array
    {
        return glob(resource_path('js/*.js')) ?: [];
    }

    /**
     * Quita comentarios para no confundir menciones en la documentación con
     * código real (por ejemplo, un docblock que explique la regla).
     */
    private function code(string $contents): string
    {
        $stripped = preg_replace('~/\*.*?\*/~s', '', $contents) ?? $contents;

        return preg_replace('~^\s*//.*$~m', '', $stripped) ?? $stripped;
    }

    public function test_the_scripts_never_write_user_text_with_innerhtml(): void
    {
        $this->assertNotEmpty($this->scripts(), 'Se esperaba al menos un script en resources/js.');

        foreach ($this->scripts() as $script) {
            $code = $this->code(file_get_contents($script));

            foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write'] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $code,
                    basename($script)." no debe usar {$forbidden}: el texto se pinta con textContent."
                );
            }
        }
    }

    public function test_the_auction_script_listens_to_the_public_channel_once(): void
    {
        $contents = file_get_contents(resource_path('js/auction.js'));

        $this->assertStringContainsString("import './echo'", $contents);
        $this->assertSame(1, substr_count($contents, ".channel('auction')"));
        $this->assertSame(1, substr_count($contents, ".listen('.AuctionStateUpdated'"));
    }

    /**
     * El liderazgo del lote se deduce del estado público del lote, no del
     * bloque `me`: el broadcast no lleva `me`, así que al abrirse un lote nuevo
     * ese booleano quedaría pegado al lote anterior (bug real visto al probar
     * dos navegadores a mano).
     */
    public function test_leadership_is_read_from_the_public_lot(): void
    {
        $code = $this->code(file_get_contents(resource_path('js/auction.js')));

        $this->assertStringContainsString(
            'const leading = isLeader(lot)',
            $code,
            'El botón de puja debe decidir con el lote actual, no con `me.is_leader`.'
        );
        $this->assertStringContainsString('lot.leader_name === name', $code);
    }

    /**
     * Las URLs del cliente son un contrato con las rutas del servidor: si una
     * se renombra, el cliente fallaría en silencio.
     */
    public function test_the_client_urls_match_the_server_routes(): void
    {
        $contents = file_get_contents(resource_path('js/auction.js'));

        foreach ([
            route('auction.state', absolute: false),
            route('auction.join', absolute: false),
            route('host.login', absolute: false),
            route('auction.lots.store', absolute: false),
            route('auction.lots.close', absolute: false),
            route('auction.bids.store', absolute: false),
        ] as $uri) {
            $this->assertStringContainsString("'{$uri}'", $contents, "El cliente no conoce {$uri}.");
        }
    }

    /**
     * El cliente no manda montos: la puja efectiva la calcula el servidor con
     * la información que tiene en la transacción. Solo viaja un `action_id`
     * nuevo por intento, que es lo que hace la acción idempotente.
     */
    public function test_the_bid_request_proposes_an_integer_amount(): void
    {
        $code = $this->code(file_get_contents(resource_path('js/auction.js')));

        $this->assertStringContainsString(
            'postJson(endpoints.bid, { action_id: newActionId(), amount_cents: amountCents })',
            $code,
            'La puja propone un monto (RF-03) y lleva un action_id para los reintentos.'
        );

        // Los centavos salen de partir el texto, no de multiplicar un float.
        $this->assertStringContainsString('function inputToCents(value)', $code);
        $this->assertStringNotContainsString('* 100)', $code);
    }

    /**
     * El temporizador se calcula con el `remaining_ms` del servidor, no con el
     * reloj del navegador (RF-08).
     */
    public function test_the_countdown_uses_the_server_remaining_time(): void
    {
        $code = $this->code(file_get_contents(resource_path('js/auction.js')));

        $this->assertStringContainsString('lot.remaining_ms', $code);
    }

    /**
     * El cierre del subastador también es idempotente: manda solo un `action_id`.
     */
    public function test_the_close_request_carries_only_an_action_id(): void
    {
        $code = $this->code(file_get_contents(resource_path('js/auction.js')));

        $this->assertStringContainsString(
            'postJson(endpoints.closeLot, { action_id: newActionId() })',
            $code,
            'El cierre debe ir solo con un action_id.'
        );
    }

    /**
     * La apertura también es idempotente (Tarea 2): va con un `action_id`, así
     * un doble clic repite la respuesta en lugar de encolar un duplicado.
     */
    public function test_the_open_request_carries_an_action_id(): void
    {
        $code = $this->code(file_get_contents(resource_path('js/auction.js')));

        $this->assertMatchesRegularExpression(
            '/payload: \(data\) => \(\{\s*action_id: newActionId\(\),/s',
            $code,
            'La apertura debe mandar un action_id.'
        );
    }

    /**
     * La pausa entre lotes se cuenta con la marca del servidor y, al llegar a
     * cero, cada navegador pide el estado con un retraso aleatorio: la ráfaga
     * de muchos testers no cae toda junta (Tarea 2).
     */
    public function test_the_pause_countdown_uses_the_server_mark_and_spreads_the_sync(): void
    {
        $code = $this->code(file_get_contents(resource_path('js/auction.js')));

        $this->assertStringContainsString('queue?.opens_in_ms', $code);
        $this->assertStringContainsString('Math.random() * SYNC_JITTER_MS', $code);
    }

    /**
     * Si quitar un lote deja la cola vacía durante la pausa, la marca llega
     * null (o ya no existe el bloque) y el cliente corta esa cuenta atrás:
     * nada queda corriendo ni pidiendo estado en bucle (Tarea 2).
     */
    public function test_the_pause_countdown_stops_when_the_server_sends_no_mark(): void
    {
        $code = $this->code(file_get_contents(resource_path('js/auction.js')));

        $this->assertStringContainsString(
            "countdownDeadline = typeof pause === 'number' ? Date.now() + pause : null;",
            $code
        );
        $this->assertStringContainsString('if (countdownDeadline === null || syncRequested)', $code);
    }

    /**
     * El estado se pide por HTTP al cargar: Reverb no reemplaza al endpoint
     * (regla de oro del proyecto).
     */
    public function test_the_client_loads_the_state_over_http(): void
    {
        $contents = $this->code(file_get_contents(resource_path('js/auction.js')));

        $this->assertStringContainsString('loadState()', $contents);
        $this->assertStringContainsString('fetch(endpoints.state', $contents);
    }

    /**
     * El historial de lotes se dibuja desde el snapshot público (RF-13), con la
     * lista ya ordenada y acotada por el servidor.
     */
    public function test_the_history_block_is_rendered_from_the_snapshot(): void
    {
        $code = $this->code(file_get_contents(resource_path('js/auction.js')));

        $this->assertStringContainsString("'[data-history]'", $code);
        $this->assertStringContainsString("'[data-history-empty]'", $code);
        $this->assertStringContainsString("'history' in snapshot", $code);
        $this->assertStringContainsString('renderHistory(state.history)', $code);
        $this->assertStringContainsString('dataset.historyItem', $code);
    }

    /**
     * Los estados de la conexión se traducen para el encabezado (color y
     * etiqueta) y al reconectar se vuelve a pedir el estado por HTTP: Reverb no
     * reemplaza al endpoint.
     */
    public function test_the_connection_states_are_translated_and_resync(): void
    {
        $code = $this->code(file_get_contents(resource_path('js/auction.js')));

        foreach (['connected', 'connecting', 'disconnected', 'unavailable', 'failed'] as $state) {
            $this->assertStringContainsString("{$state}:", $code, "Falta el estado `{$state}` en el cliente.");
        }

        foreach (['En vivo', 'Conectando…', 'Reconectando…', 'Sin conexión'] as $label) {
            $this->assertStringContainsString($label, $code, "Falta la etiqueta `{$label}`.");
        }

        foreach (['bg-emerald-400', 'bg-amber-400', 'bg-rose-500'] as $tone) {
            $this->assertStringContainsString($tone, $code, "Falta el color `{$tone}` de un estado.");
        }

        $this->assertStringContainsString("connection.bind('state_change'", $code);
        $this->assertStringContainsString('loadState()', $code);
    }

    /**
     * El chat se carga desde `auction.js` como un módulo aparte.
     */
    public function test_the_auction_script_starts_the_chat(): void
    {
        $this->assertStringContainsString(
            "import './chat'",
            file_get_contents(resource_path('js/auction.js'))
        );
    }

    /**
     * El chat escucha el mismo canal público, una sola vez por evento.
     */
    public function test_the_chat_script_listens_to_the_public_channel_once(): void
    {
        $contents = file_get_contents(resource_path('js/chat.js'));

        $this->assertSame(1, substr_count($contents, ".channel('auction')"));
        $this->assertSame(1, substr_count($contents, ".listen('.ChatMessagePosted'"));
        $this->assertSame(1, substr_count($contents, ".listen('.ChatMessageDeleted'"));
    }

    /**
     * Las URLs del chat son un contrato con las rutas del servidor.
     */
    public function test_the_chat_script_knows_the_chat_routes(): void
    {
        $code = $this->code(file_get_contents(resource_path('js/chat.js')));

        $this->assertStringContainsString(
            "'".route('auction.chat.index', absolute: false)."'",
            $code,
            'El chat no conoce el endpoint del historial.'
        );

        // El borrado arma la URL con el id del mensaje (misma base que el historial).
        $this->assertStringContainsString('method: \'DELETE\'', $code);
    }

    /**
     * El historial se pide por HTTP al cargar y al reconectar: Reverb no
     * reemplaza al endpoint (regla de oro del proyecto).
     */
    public function test_the_chat_script_loads_the_history_over_http(): void
    {
        $code = $this->code(file_get_contents(resource_path('js/chat.js')));

        $this->assertStringContainsString('loadChat()', $code);
        $this->assertStringContainsString('request(endpoints.chat', $code);
        $this->assertStringContainsString("'state_change'", $code);
    }

    /**
     * El mensaje lleva un `action_id`: un reintento de red no lo duplica (6).
     */
    public function test_the_chat_message_carries_an_action_id(): void
    {
        $code = $this->code(file_get_contents(resource_path('js/chat.js')));

        $this->assertStringContainsString('JSON.stringify({ body, action_id: newActionId() })', $code);
    }

    /**
     * Presencia: Echo.join sobre el canal autorizado, una sola vez, y los
     * nombres se pintan como texto.
     */
    public function test_the_presence_script_joins_the_presence_channel(): void
    {
        $code = $this->code(file_get_contents(resource_path('js/presence.js')));

        $this->assertSame(1, substr_count($code, "window.Echo.join('auction.presence')"));
        $this->assertStringContainsString('.here(', $code);
        $this->assertStringContainsString('.joining(', $code);
        $this->assertStringContainsString('.leaving(', $code);
        $this->assertStringNotContainsString('innerHTML', $code);
        $this->assertStringContainsString("import './presence';", file_get_contents(resource_path('js/auction.js')));
    }

    /**
     * Las horas se muestran en 24 horas: con `es-AR` el formato por defecto es
     * de 12 horas sin "a. m./p. m." y las 15:30 se leían como 03:30.
     */
    public function test_times_are_formatted_with_a_24_hour_clock(): void
    {
        foreach (['js/auction.js', 'js/chat.js'] as $script) {
            $code = $this->code(file_get_contents(resource_path($script)));

            preg_match_all("/toLocale(?:Time)?String\\('es-AR'([^)]*)\\)/", $code, $calls);

            $this->assertNotEmpty($calls[0], "{$script} no formatea horas.");

            foreach ($calls[1] as $options) {
                $this->assertStringContainsString("hourCycle: 'h23'", $options, "{$script}: falta hourCycle h23.");
            }
        }
    }
}
