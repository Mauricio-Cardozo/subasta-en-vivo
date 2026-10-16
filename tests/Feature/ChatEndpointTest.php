<?php

namespace Tests\Feature;

use App\Events\ChatMessagePosted;
use App\Models\AuctionRoom;
use App\Models\ChatMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * POST /auction/chat: identidad, publicación y evento (RF-11).
 *
 * Las altas van por `POST /join` de verdad para que la sesión sea la misma entre
 * peticiones, como en el navegador.
 */
class ChatEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('auction.host_pin', 'pin-secreto');

        AuctionRoom::factory()->create();
    }

    private function join(string $name): void
    {
        $this->postJson('/join', ['name' => $name])->assertCreated();
    }

    private function loginHost(): void
    {
        $this->postJson('/host/login', ['pin' => 'pin-secreto'])->assertOk();
    }

    public function test_a_message_requires_an_identity(): void
    {
        $this->postJson('/auction/chat', ['body' => 'Hola'])
            ->assertForbidden()
            ->assertJson(['reason' => 'unauthenticated']);

        $this->assertSame(0, ChatMessage::query()->count());
    }

    public function test_a_joined_bidder_posts_a_message(): void
    {
        $this->join('Ana');

        $response = $this->postJson('/auction/chat', ['body' => '  Hola a todos  '])
            ->assertCreated()
            ->assertJson([
                'author_name' => 'Ana',
                'body' => 'Hola a todos',
            ]);

        $this->assertIsInt($response->json('id'));
        $this->assertNotEmpty($response->json('posted_at'));

        $message = ChatMessage::query()->sole();
        $this->assertSame('Ana', $message->author_name);
        $this->assertSame('Hola a todos', $message->body);
        $this->assertNotNull($message->participant_id);
    }

    public function test_the_host_posts_a_message_without_a_participant(): void
    {
        $this->loginHost();

        $this->postJson('/auction/chat', ['body' => 'Arrancamos'])
            ->assertCreated()
            ->assertJson(['author_name' => 'Subastador']);

        $message = ChatMessage::query()->sole();
        $this->assertNull($message->participant_id);
        $this->assertSame('Subastador', $message->author_name);
    }

    public function test_a_posted_message_is_broadcast(): void
    {
        Event::fake([ChatMessagePosted::class]);
        $this->join('Ana');

        $this->postJson('/auction/chat', ['body' => 'Hola'])->assertCreated();

        Event::assertDispatched(ChatMessagePosted::class, function (ChatMessagePosted $event): bool {
            return $event->message['author_name'] === 'Ana'
                && $event->message['body'] === 'Hola'
                && is_int($event->message['id']);
        });
    }

    public function test_an_empty_message_is_rejected(): void
    {
        $this->join('Ana');

        $this->postJson('/auction/chat', ['body' => '   '])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');

        $this->assertSame(0, ChatMessage::query()->count());
    }

    public function test_a_too_long_message_is_rejected(): void
    {
        $this->join('Ana');

        $this->postJson('/auction/chat', ['body' => str_repeat('x', 281)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');

        $this->assertSame(0, ChatMessage::query()->count());
    }

    public function test_a_rejected_message_is_not_broadcast(): void
    {
        Event::fake([ChatMessagePosted::class]);
        $this->join('Ana');

        $this->postJson('/auction/chat', ['body' => ''])->assertStatus(422);

        Event::assertNotDispatched(ChatMessagePosted::class);
    }

    public function test_posting_is_rate_limited_per_sender(): void
    {
        $this->join('Ana');

        $this->postJson('/auction/chat', ['body' => 'Uno'])->assertCreated();
        $this->postJson('/auction/chat', ['body' => 'Dos'])->assertStatus(429);

        $this->assertSame(1, ChatMessage::query()->count());
    }
}
