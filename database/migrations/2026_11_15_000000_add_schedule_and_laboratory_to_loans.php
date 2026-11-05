<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Solicitud remota del profesor: laboratorio (`location_id`) y horario (`start_time`, `end_time`).
     * Nullable para no romper los préstamos antiguos ni los presenciales. `time_block` y `room`
     * se conservan (se rellenan al crear) para la cola y las exportaciones.
     */
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('storeroom_id')->constrained('locations')->nullOnDelete();
            $table->time('start_time')->nullable()->after('loan_date');
            $table->time('end_time')->nullable()->after('start_time');
            $table->index('location_id');
        });

        // Backfill: "HH:MM - HH:MM" -> start_time / end_time.
        DB::statement(<<<'SQL'
            UPDATE loans
            SET start_time = (substring(time_block from '^\s*(\d{1,2}:\d{2})'))::time,
                end_time = (substring(time_block from '(\d{1,2}:\d{2})\s*$'))::time
            WHERE time_block ~ '^\s*([01]?\d|2[0-3]):[0-5]\d\s*-\s*([01]?\d|2[0-3]):[0-5]\d\s*$'
        SQL);
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('location_id');
            $table->dropColumn(['start_time', 'end_time']);
        });
    }
};
