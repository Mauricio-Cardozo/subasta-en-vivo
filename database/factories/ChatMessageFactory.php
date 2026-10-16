<?php

namespace Database\Factories;

use App\Models\AuctionRoom;
use App\Models\ChatMessage;
use App\Models\Participant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatMessage>
 */
class ChatMessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'room_id' => AuctionRoom::factory(),
            'participant_id' => Participant::factory(),
            'author_name' => fake()->firstName(),
            'body' => fake()->sentence(),
        ];
    }

    /**
     * Mensaje del subastador: sin fila de participante.
     */
    public function fromHost(): static
    {
        return $this->state(fn (array $attributes): array => [
            'participant_id' => null,
            'author_name' => 'Subastador',
        ]);
    }
}
