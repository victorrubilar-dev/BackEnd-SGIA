<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-18: resultado de la mantención y estados previos de la unidad y del informe
 * (para restaurarlos si la mantención se anula).
 * REQ-19: nombre original y tamaño del archivo de cada documento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_records', function (Blueprint $table) {
            // operativo | baja (solo al finalizar)
            $table->string('result', 20)->nullable()->after('status');
            $table->string('previous_unit_status', 50)->nullable()->after('result');
            $table->string('previous_report_status', 50)->nullable()->after('previous_unit_status');
            $table->index(['status', 'started_at']);
        });

        Schema::table('maintenance_documents', function (Blueprint $table) {
            $table->string('original_name')->nullable()->after('file_path');
            $table->unsignedBigInteger('size_bytes')->nullable()->after('original_name');
            $table->index(['equipment_unit_id', 'document_type']);
            $table->index('valid_until');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_documents', function (Blueprint $table) {
            $table->dropIndex(['equipment_unit_id', 'document_type']);
            $table->dropIndex(['valid_until']);
            $table->dropColumn(['original_name', 'size_bytes']);
        });

        Schema::table('maintenance_records', function (Blueprint $table) {
            $table->dropIndex(['status', 'started_at']);
            $table->dropColumn(['result', 'previous_unit_status', 'previous_report_status']);
        });
    }
};
