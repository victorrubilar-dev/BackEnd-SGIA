<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique()->index();
            // remoto (solicitud del docente) | presencial (registro directo en pañol)
            $table->string('type')->default('remoto')->index();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            // Persona que retira el material (docente o estudiante)
            $table->string('borrower_name')->nullable();
            $table->string('borrower_document')->nullable();
            $table->string('subject')->nullable();
            $table->string('room')->nullable();
            $table->date('loan_date')->nullable()->index();
            $table->string('time_block')->nullable();
            // Máquina de estados: pendiente -> en_proceso -> procesado | rechazado
            $table->string('status')->default('pendiente')->index();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};
