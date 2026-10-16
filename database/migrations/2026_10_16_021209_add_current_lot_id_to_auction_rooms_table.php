<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Puntero al lote activo de la sala (RF-01).
     *
     * Se agrega después de `lots` para romper la referencia circular entre
     * `auction_rooms.current_lot_id` y `lots.room_id`. `AuctionService` mantiene
     * este puntero y `lots.status` consistentes dentro de la misma transacción.
     */
    public function up(): void
    {
        Schema::table('auction_rooms', function (Blueprint $table) {
            $table->foreignId('current_lot_id')
                ->nullable()
                ->after('version')
                ->constrained('lots')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('auction_rooms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_lot_id');
        });
    }
};
