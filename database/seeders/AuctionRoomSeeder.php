<?php

namespace Database\Seeders;

use App\Models\AuctionRoom;
use Illuminate\Database\Seeder;

/**
 * El MVP juega con una única sala compartida (RF-01).
 */
class AuctionRoomSeeder extends Seeder
{
    public function run(): void
    {
        if (AuctionRoom::query()->exists()) {
            return;
        }

        AuctionRoom::query()->create([
            'version' => 0,
            'current_lot_id' => null,
        ]);
    }
}
