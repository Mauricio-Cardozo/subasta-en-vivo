<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lotes de la sala. Como máximo uno puede estar `open` a la vez (RF-01).
     *
     * Todos los montos son enteros en centavos (RNF-03):
     * `current_price_cents` queda en null hasta la primera puja, así el primer
     * pujador iguala el precio inicial en lugar de superarlo.
     */
    public function up(): void
    {
        Schema::create('lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('auction_rooms')->cascadeOnDelete();
            $table->string('title');
            $table->string('description')->nullable();
            $table->unsignedBigInteger('start_price_cents');
            $table->unsignedBigInteger('min_increment_cents');
            $table->unsignedBigInteger('current_price_cents')->nullable();
            $table->foreignId('leader_participant_id')->nullable()->constrained('participants')->nullOnDelete();
            $table->string('status')->default('open');
            $table->timestamp('started_at');
            $table->timestamp('ends_at');
            // Contador de extensiones anti-sniping (4.3).
            $table->unsignedInteger('extensions')->default(0);
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('winner_participant_id')->nullable()->constrained('participants')->nullOnDelete();
            $table->timestamps();

            // Lote activo de la sala: `where('status', 'open')`.
            $table->index(['room_id', 'status']);
            // Historial de lotes cerrados por fecha.
            $table->index('closed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lots');
    }
};
