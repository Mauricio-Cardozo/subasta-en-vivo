<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pujas confirmadas (RF-04).
     *
     * Cada fila es una puja ya aceptada por el servidor: el monto es el que
     * quedó como precio actual del lote en esa transición (RNF-03, enteros en
     * centavos). Las pujas son inmutables: no se editan ni se borran.
     */
    public function up(): void
    {
        Schema::create('bids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lot_id')->constrained('lots')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('participants')->cascadeOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->timestamps();

            // Historial ordenado del lote: `where('lot_id', …)->orderBy('id')`.
            $table->index(['lot_id', 'id']);
            // Pujas de un participante (historial y auditoría).
            $table->index('participant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bids');
    }
};
