<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingresos_ocr', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->restrictOnDelete();
            $table->foreignId('presupuesto_anual_id')->constrained('presupuestos_anuales')->restrictOnDelete();
            $table->string('archivo_pdf_url', 255)->nullable();
            $table->string('numero_documento', 100)->nullable();
            $table->decimal('monto_total_descontado', 15, 2)->nullable();
            $table->jsonb('raw_ocr_payload')->nullable();
            $table->string('estado', 50)->nullable();
            $table->timestamp('fecha_ingreso')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingresos_ocr');
    }
};
