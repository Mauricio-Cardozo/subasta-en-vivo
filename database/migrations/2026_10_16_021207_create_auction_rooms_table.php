<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La sala compartida: una sola fila en el MVP. Es la fila que se bloquea
     * (`lockForUpdate`) al inicio de toda acción, para serializar el acceso al
     * estado (RNF-02).
     *
     * `version` es la versión del estado persistido: sube una vez por
     * transición confirmada (RNF-04). Es `unsignedBigInteger` para no agotarse.
     */
    public function up(): void
    {
        Schema::create('auction_rooms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('version')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auction_rooms');
    }
};
