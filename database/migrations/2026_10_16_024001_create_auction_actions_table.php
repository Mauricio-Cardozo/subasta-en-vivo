<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Acciones idempotentes (4.7).
     *
     * El navegador manda un `action_id` con cada acción; la restricción única
     * `(actor_key, action_id)` garantiza que reintentar (doble clic, reconexión)
     * no cree una segunda puja: la respuesta original se guarda y se repite.
     *
     * `actor_key` es el hash de la sesión (`participants.session_id`), nunca el
     * id de sesión en claro.
     */
    public function up(): void
    {
        Schema::create('auction_actions', function (Blueprint $table) {
            $table->id();
            $table->string('actor_key', 64);
            $table->string('action_id', 64);
            // Respuesta original de la acción, para repetirla sin recálculo.
            $table->json('result')->nullable();
            $table->timestamps();

            $table->unique(['actor_key', 'action_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auction_actions');
    }
};
