<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Participantes de la sala única. No hay login: la identidad sale de la
     * sesión del servidor y `session_id` guarda su hash (pseudonimizado).
     */
    public function up(): void
    {
        Schema::create('participants', function (Blueprint $table) {
            $table->id();
            $table->string('session_id')->unique();
            $table->string('name');
            // Saldo virtual en centavos: nunca float (RNF-03).
            $table->unsignedBigInteger('balance_cents');
            $table->timestamps();

            // RF: nombre único por sala.
            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('participants');
    }
};
