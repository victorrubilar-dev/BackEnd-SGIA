<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentos_mantenimiento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipo_id')->constrained('equipos')->cascadeOnDelete();
            $table->foreignId('registro_mantencion_id')->nullable()->constrained('registros_mantencion')->nullOnDelete();
            $table->foreignId('subido_por_id')->constrained('usuarios')->restrictOnDelete();
            $table->string('tipo_documento', 50);
            $table->string('titulo', 150);
            $table->string('archivo_pdf_url', 255);
            $table->timestamp('fecha_vigencia')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentos_mantenimiento');
    }
};
