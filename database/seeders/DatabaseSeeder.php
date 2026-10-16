<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // No hay usuarios en el MVP: la identidad de cada pujador sale de la
        // sesión (5.5), así que no se siembra ningún usuario de prueba.
        $this->call(AuctionRoomSeeder::class);
    }
}
