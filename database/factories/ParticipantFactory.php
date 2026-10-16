<?php

namespace Database\Factories;

use App\Models\Participant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Participant>
 */
class ParticipantFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'session_id' => hash('sha256', fake()->unique()->uuid()),
            'name' => fake()->unique()->firstName(),
            'balance_cents' => config('auction.participant.starting_balance_cents'),
        ];
    }
}
