<?php

namespace Database\Factories;

use App\Enums\LotStatus;
use App\Models\AuctionRoom;
use App\Models\Lot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lot>
 */
class LotFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startPrice = fake()->numberBetween(100, 50_000);

        return [
            'room_id' => AuctionRoom::factory(),
            'title' => fake()->sentence(3),
            'description' => null,
            'start_price_cents' => $startPrice,
            'min_increment_cents' => config('auction.lot.min_increment_cents'),
            'current_price_cents' => null,
            'leader_participant_id' => null,
            'status' => LotStatus::Open,
            'started_at' => now(),
            'ends_at' => now()->addSeconds(config('auction.lot.duration_seconds')),
            'extensions' => 0,
            'closed_at' => null,
            'winner_participant_id' => null,
        ];
    }

    /**
     * Lote cerrado por tiempo, opcionalmente con ganador.
     */
    public function closed(): static
    {
        return $this->state(fn (): array => [
            'status' => LotStatus::Sold,
            'closed_at' => now(),
            'ends_at' => now()->subSecond(),
        ]);
    }

    /**
     * Lote ya vencido pero todavía marcado como abierto.
     */
    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => LotStatus::Open,
            'ends_at' => now()->subSecond(),
        ]);
    }
}
