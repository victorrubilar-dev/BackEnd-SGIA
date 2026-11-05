<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Formulario del docente: nombre, asignatura, fecha, laboratorio, problemas marcados
     * (cada uno con su descripción), sugerencia y firma. Los informes anteriores (por equipo)
     * quedan con estas columnas en null. El equipo asociado pasa a ser opcional.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE incident_reports ALTER COLUMN product_id DROP NOT NULL');

        Schema::table('incident_reports', function (Blueprint $table) {
            $table->string('teacher_name')->nullable();
            $table->string('subject', 100)->nullable();
            $table->date('report_date')->nullable()->index();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->jsonb('problems')->nullable();
            $table->text('suggestion')->nullable();
            $table->string('signature_path')->nullable();
            $table->timestamp('signed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('incident_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('location_id');
            $table->dropColumn(['teacher_name', 'subject', 'report_date', 'problems', 'suggestion', 'signature_path', 'signed_at']);
        });

        DB::statement('DELETE FROM incident_reports WHERE product_id IS NULL');
        DB::statement('ALTER TABLE incident_reports ALTER COLUMN product_id SET NOT NULL');
    }
};
