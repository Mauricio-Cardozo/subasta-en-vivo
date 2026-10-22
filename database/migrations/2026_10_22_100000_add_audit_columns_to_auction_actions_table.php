<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `auction_actions` pasa a ser la auditoría persistente (RF-15, 5.3).
     *
     * Cada acción (puja, apertura, cierre, chat, moderación) deja una fila,
     * aceptada o rechazada, con quién (`participant_id`, `actor_key`), desde
     * dónde (`ip`), qué (`type`, `payload`), con qué resultado (`accepted`,
     * `reason`, `result`) y en qué petición (`correlation_id`). La misma fila
     * sigue sirviendo para la idempotencia (4.7): `action_id` pasa a ser
     * opcional y `payload_hash` detecta un `action_id` reusado con otro
     * contenido. La restricción única `(actor_key, action_id)` admite varias
     * filas sin `action_id`.
     */
    public function up(): void
    {
        Schema::table('auction_actions', function (Blueprint $table) {
            $table->string('action_id', 64)->nullable()->change();
            // `session:` + sha256 (72 caracteres) para quien todavía no tiene
            // identidad: con 64, PostgreSQL rechaza la fila de su denegación.
            $table->string('actor_key', 100)->change();

            $table->string('type', 32)->default('action')->after('id');
            $table->foreignId('participant_id')->nullable()->after('type')
                ->constrained('participants')->nullOnDelete();
            $table->string('ip', 45)->nullable()->after('participant_id');
            $table->json('payload')->nullable()->after('action_id');
            $table->string('payload_hash', 64)->nullable()->after('payload');
            // Null mientras la acción está en curso.
            $table->boolean('accepted')->nullable()->after('payload_hash');
            $table->string('reason', 64)->nullable()->after('accepted');
            $table->string('correlation_id', 64)->nullable()->after('result');

            $table->index(['type', 'created_at']);
            $table->index('correlation_id');
        });
    }

    public function down(): void
    {
        Schema::table('auction_actions', function (Blueprint $table) {
            $table->dropIndex(['type', 'created_at']);
            $table->dropIndex(['correlation_id']);
            $table->dropConstrainedForeignId('participant_id');
            $table->dropColumn(['type', 'ip', 'payload', 'payload_hash', 'accepted', 'reason', 'correlation_id']);
        });

        // El esquema viejo exige `action_id` y una `actor_key` de hasta 64: las
        // filas de solo auditoría no entran y se descartan.
        DB::table('auction_actions')->whereNull('action_id')->delete();

        Schema::table('auction_actions', function (Blueprint $table) {
            $table->string('action_id', 64)->nullable(false)->change();
            $table->string('actor_key', 64)->change();
        });
    }
};
