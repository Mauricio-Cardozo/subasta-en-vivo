<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cola de lotes (Tarea 2).
     *
     * `lots.duration_seconds` guarda la duración elegida al cargar el lote: un
     * lote encolado la conserva hasta que le toca abrirse. `started_at` y
     * `ends_at` pasan a ser nullables porque un lote en cola todavía no arrancó
     * ni tiene plazo. `auction_rooms.queue_opens_at` guarda el momento en que
     * abre el siguiente lote de la cola (la pausa entre lotes); null si no hay
     * pausa pendiente.
     */
    public function up(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            $table->unsignedInteger('duration_seconds')
                ->default(60)
                ->after('min_increment_cents');

            $table->timestamp('started_at')->nullable()->change();
            $table->timestamp('ends_at')->nullable()->change();
        });

        Schema::table('auction_rooms', function (Blueprint $table) {
            $table->timestamp('queue_opens_at')
                ->nullable()
                ->after('current_lot_id');
        });
    }

    public function down(): void
    {
        Schema::table('auction_rooms', function (Blueprint $table) {
            $table->dropColumn('queue_opens_at');
        });

        // Un lote en cola no tiene inicio ni plazo y el esquema viejo los exige:
        // se quitan esas filas antes de volver las columnas a NOT NULL.
        DB::table('lots')->whereNull('started_at')->orWhereNull('ends_at')->delete();

        Schema::table('lots', function (Blueprint $table) {
            $table->timestamp('started_at')->nullable(false)->change();
            $table->timestamp('ends_at')->nullable(false)->change();

            $table->dropColumn('duration_seconds');
        });
    }
};
