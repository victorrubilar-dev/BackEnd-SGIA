<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('rut', 20)->unique();
            $table->string('full_name', 150)->nullable();
            $table->string('email', 150)->nullable();
            $table->foreignId('career_id')->nullable()->constrained('careers')->nullOnDelete();
            $table->timestamp('registered_at')->useCurrent();
            $table->timestamps();
        });

        Schema::table('incident_reports', function (Blueprint $table) {
            $table->foreignId('equipment_unit_id')->nullable()->constrained('equipment_units')->restrictOnDelete();
            // falla | reposicion | mantencion
            $table->string('request_type', 50)->default('falla');
            $table->string('evidence_photo_path')->nullable();
        });

        Schema::create('maintenance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_unit_id')->constrained('equipment_units')->restrictOnDelete();
            $table->foreignId('responsible_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('external_supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignId('incident_report_id')->nullable()->constrained('incident_reports')->nullOnDelete();
            // preventiva | correctiva | calibracion
            $table->string('maintenance_type', 50);
            // medios_propios | externa
            $table->string('execution_mode', 30)->default('medios_propios');
            $table->boolean('rev_a_approved')->default(false);
            $table->boolean('rev_b_approved')->default(false);
            $table->boolean('rev_c_approved')->nullable();
            $table->boolean('pfg_approved')->default(false);
            $table->boolean('requires_additional_maintenance')->default(false);
            $table->boolean('applies_recf')->default(false);
            $table->decimal('repair_cost', 15, 2)->default(0);
            $table->text('spare_parts_used')->nullable();
            $table->text('technical_notes')->nullable();
            // en_curso | finalizada | anulada
            $table->string('status', 50)->default('en_curso');
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['equipment_unit_id', 'status']);
        });

        Schema::create('maintenance_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_unit_id')->constrained('equipment_units')->cascadeOnDelete();
            $table->foreignId('maintenance_record_id')->nullable()->constrained('maintenance_records')->nullOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('document_type', 50);
            $table->string('title', 150);
            $table->string('file_path');
            $table->timestamp('valid_until')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_documents');
        Schema::dropIfExists('maintenance_records');
        Schema::table('incident_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('equipment_unit_id');
            $table->dropColumn(['request_type', 'evidence_photo_path']);
        });
        Schema::dropIfExists('students');
    }
};
