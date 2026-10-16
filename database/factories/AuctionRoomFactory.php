<?php

namespace Database\Factories;

use App\Models\AuctionRoom;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuctionRoom>
 */
class AuctionRoomFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'version' => 0,
            'current_lot_id' => null,
        ];
    }
}
