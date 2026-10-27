<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('informes_novedades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->restrictOnDelete();
            $table->foreignId('equipo_id')->constrained('equipos')->restrictOnDelete();
            $table->string('tipo_solicitud', 50);
            $table->text('descripcion')->nullable();
            $table->string('foto_evidencia_url', 255)->nullable();
            $table->string('documento_adjunto_url', 255)->nullable();
            $table->string('estado', 50);
            $table->timestamp('fecha_reporte')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('informes_novedades');
    }
};
