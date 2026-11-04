<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avisos programados (`sgia:send-reminders`): marca qué ya se notificó para no repetir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->timestamp('due_reminder_sent_at')->nullable();
            $table->timestamp('overdue_notified_at')->nullable();
        });

        Schema::table('equipment_units', function (Blueprint $table) {
            // Fecha de `next_maintenance_at` ya avisada a PAN-01 (si cambia, se vuelve a avisar).
            $table->date('maintenance_notified_for')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('equipment_units', function (Blueprint $table) {
            $table->dropColumn('maintenance_notified_for');
        });

        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn(['due_reminder_sent_at', 'overdue_notified_at']);
        });
    }
};
