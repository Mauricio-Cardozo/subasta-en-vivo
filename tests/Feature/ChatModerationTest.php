<?php

namespace Tests\Feature;

use App\Events\ChatMessageDeleted;
use App\Models\AuctionRoom;
use App\Models\ChatMessage;
use App\Models\Participant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Historial (RF-12) y moderación del chat (RF-11).
 */
class ChatModerationTest extends TestCase
{
    use RefreshDatabase;

    private AuctionRoom $room;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('auction.host_pin', 'pin-secreto');

        $this->room = AuctionRoom::factory()->create();
    }

    private function join(string $name): void
    {
        $this->postJson('/join', ['name' => $name])->assertCreated();
    }

    private function loginHost(): void
    {
        $this->postJson('/host/login', ['pin' => 'pin-secreto'])->assertOk();
    }

    private function messageFrom(Participant $author, string $body = 'Hola'): ChatMessage
    {
        return ChatMessage::factory()->create([
            'room_id' => $this->room->id,
            'participant_id' => $author->id,
            'author_name' => $author->name,
            'body' => $body,
        ]);
    }

    public function test_the_history_returns_the_messages_in_order(): void
    {
        $ana = Participant::factory()->create(['name' => 'Ana']);
        $this->messageFrom($ana, 'Primero');
        $this->messageFrom($ana, 'Segundo');
        $this->messageFrom($ana, 'Tercero');

        $response = $this->getJson('/auction/chat')->assertOk();

        $this->assertStringContainsString(
            'no-store',
            (string) $response->headers->get('Cache-Control')
        );

        $messages = $response->json('messages');

        $this->assertCount(3, $messages);
        $this->assertSame(['Primero', 'Segundo', 'Tercero'], array_column($messages, 'body'));
        $this->assertSame('Ana', $messages[0]['author_name']);
        $this->assertIsInt($messages[0]['id']);
        $this->assertNotEmpty($messages[0]['posted_at']);
    }

    public function test_the_history_is_truncated_to_the_configured_size(): void
    {
        config()->set('auction.chat.history', 2);

        $ana = Participant::factory()->create(['name' => 'Ana']);
        $this->messageFrom($ana, 'Uno');
        $this->messageFrom($ana, 'Dos');
        $this->messageFrom($ana, 'Tres');

        $messages = $this->getJson('/auction/chat')->assertOk()->json('messages');

        // Los últimos dos, en orden de llegada.
        $this->assertSame(['Dos', 'Tres'], array_column($messages, 'body'));
    }

    public function test_the_author_deletes_their_own_message(): void
    {
        Event::fake([ChatMessageDeleted::class]);
        $this->join('Ana');

        $id = $this->postJson('/auction/chat', ['body' => 'Oops'])
            ->assertCreated()
            ->json('id');

        $this->deleteJson("/auction/chat/{$id}")->assertNoContent();

        $this->assertDatabaseMissing('chat_messages', ['id' => $id]);
        Event::assertDispatched(
            ChatMessageDeleted::class,
            fn (ChatMessageDeleted $event): bool => $event->messageId === $id
        );
    }

    public function test_the_host_deletes_any_message(): void
    {
        Event::fake([ChatMessageDeleted::class]);
        $ana = Participant::factory()->create(['name' => 'Ana']);
        $message = $this->messageFrom($ana, 'Fuera de tono');

        $this->loginHost();

        $this->deleteJson("/auction/chat/{$message->id}")->assertNoContent();

        $this->assertDatabaseMissing('chat_messages', ['id' => $message->id]);
        Event::assertDispatched(ChatMessageDeleted::class);
    }

    public function test_another_bidder_cannot_delete_a_message(): void
    {
        Event::fake([ChatMessageDeleted::class]);
        $beto = Participant::factory()->create(['name' => 'Beto']);
        $message = $this->messageFrom($beto, 'Mío');

        $this->join('Ana');

        $this->deleteJson("/auction/chat/{$message->id}")
            ->assertForbidden()
            ->assertJson(['reason' => 'forbidden']);

        $this->assertDatabaseHas('chat_messages', ['id' => $message->id]);
        Event::assertNotDispatched(ChatMessageDeleted::class);
    }

    public function test_a_guest_cannot_delete_a_message(): void
    {
        $ana = Participant::factory()->create(['name' => 'Ana']);
        $message = $this->messageFrom($ana, 'Hola');

        $this->deleteJson("/auction/chat/{$message->id}")->assertForbidden();

        $this->assertDatabaseHas('chat_messages', ['id' => $message->id]);
    }

    public function test_deleting_a_missing_message_returns_not_found(): void
    {
        $this->loginHost();

        $this->deleteJson('/auction/chat/999999')->assertNotFound();
    }
}
