<?php

namespace Tests\Feature;

use App\Models\AuctionRoom;
use App\Models\ChatMessage;
use App\Models\Participant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Esquema del chat (RF-11, RF-12).
 */
class ChatSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_message_belongs_to_a_room_and_a_participant(): void
    {
        $room = AuctionRoom::factory()->create();
        $participant = Participant::factory()->create(['name' => 'Ana']);

        $message = ChatMessage::factory()->create([
            'room_id' => $room->id,
            'participant_id' => $participant->id,
            'author_name' => 'Ana',
            'body' => 'Hola a todos',
        ]);

        $this->assertTrue($message->room->is($room));
        $this->assertTrue($message->participant->is($participant));
        $this->assertSame('Hola a todos', $message->body);
        $this->assertSame(1, $room->chatMessages()->count());
        $this->assertSame(1, $participant->chatMessages()->count());
    }

    public function test_the_host_publishes_without_a_participant(): void
    {
        $message = ChatMessage::factory()->fromHost()->create();

        $this->assertNull($message->participant_id);
        $this->assertSame('Subastador', $message->author_name);
        $this->assertNull($message->participant);
    }

    public function test_the_history_is_ordered_by_id(): void
    {
        $room = AuctionRoom::factory()->create();

        $first = ChatMessage::factory()->create(['room_id' => $room->id]);
        $second = ChatMessage::factory()->create(['room_id' => $room->id]);
        $third = ChatMessage::factory()->create(['room_id' => $room->id]);

        $ids = $room->chatMessages()->orderBy('id')->pluck('id')->all();

        $this->assertSame([$first->id, $second->id, $third->id], $ids);
    }

    public function test_deleting_the_room_deletes_its_messages(): void
    {
        $room = AuctionRoom::factory()->create();
        ChatMessage::factory()->count(2)->create(['room_id' => $room->id]);

        $room->delete();

        $this->assertSame(0, ChatMessage::query()->count());
    }

    public function test_a_deleted_participant_leaves_the_message_with_its_author_name(): void
    {
        $participant = Participant::factory()->create(['name' => 'Beto']);
        $message = ChatMessage::factory()->create([
            'participant_id' => $participant->id,
            'author_name' => 'Beto',
        ]);

        $participant->delete();

        $message->refresh();
        $this->assertNull($message->participant_id);
        $this->assertSame('Beto', $message->author_name);
    }
}
