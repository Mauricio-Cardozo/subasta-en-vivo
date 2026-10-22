<?php

namespace Tests\Feature;

use App\Models\AuctionRoom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Canal de presencia `auction.presence`: quién está en la sala.
 *
 * Se autoriza con la identidad de la sesión (no hay cuentas). Firmar la
 * suscripción no sale a la red: alcanza con configurar el driver de Reverb.
 */
class PresenceChannelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        AuctionRoom::factory()->create();

        config([
            'auction.host_pin' => 'pin-secreto',
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);

        // Los canales se registraron al arrancar sobre el driver `null` de
        // phpunit.xml: se vuelven a registrar sobre el de Reverb.
        require base_path('routes/channels.php');
    }

    private function subscribe(): TestResponse
    {
        return $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'presence-auction.presence',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function member(TestResponse $response): array
    {
        return json_decode($response->json('channel_data'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_a_visitor_without_identity_cannot_join(): void
    {
        $this->subscribe()->assertForbidden();
    }

    public function test_a_bidder_joins_with_a_pseudonym_name_and_role(): void
    {
        $this->postJson('/join', ['name' => 'Ana'])->assertCreated();

        $response = $this->subscribe()->assertOk();
        $member = $this->member($response);

        $this->assertStringStartsWith('test-key:', $response->json('auth'));
        $this->assertSame(
            ['id' => $member['user_id'], 'name' => 'Ana', 'role' => 'bidder'],
            $member['user_info'],
        );
        // Seudónimo: ni el id de la base ni el de la sesión.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string) $member['user_id']);
    }

    public function test_the_host_joins_as_host(): void
    {
        $this->postJson('/host/login', ['pin' => 'pin-secreto'])->assertOk();

        $info = $this->member($this->subscribe()->assertOk())['user_info'];

        $this->assertSame('Subastador', $info['name']);
        $this->assertSame('host', $info['role']);
    }

    public function test_the_same_bidder_keeps_the_same_member_id(): void
    {
        $this->postJson('/join', ['name' => 'Ana'])->assertCreated();

        // Dos pestañas de la misma sesión cuentan como un solo miembro.
        $this->assertSame(
            $this->member($this->subscribe())['user_id'],
            $this->member($this->subscribe())['user_id'],
        );
    }
}
