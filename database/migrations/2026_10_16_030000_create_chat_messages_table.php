<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mensajes del chat de la sala (RF-11).
     *
     * El chat no entra en `version`: se ordena por `id` (RF-12). `author_name`
     * es la copia del nombre al publicar, así el subastador (que no tiene fila
     * de participante) se identifica igual y un autor borrado no deja el mensaje
     * sin nombre.
     */
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('auction_rooms')->cascadeOnDelete();
            // El subastador no tiene participante: su mensaje queda sin autor id.
            $table->foreignId('participant_id')->nullable()->constrained('participants')->nullOnDelete();
            $table->string('author_name');
            $table->text('body');
            $table->timestamps();

            // Historial de la sala: `where('room_id', …)->orderBy('id')`.
            $table->index(['room_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
