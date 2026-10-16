<?php

namespace Database\Factories;

use App\Models\Bid;
use App\Models\Lot;
use App\Models\Participant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bid>
 */
class BidFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lot_id' => Lot::factory(),
            'participant_id' => Participant::factory(),
            'amount_cents' => fake()->numberBetween(100, 50_000),
        ];
    }
}
