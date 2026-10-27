<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prestamos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('panol_id')->constrained('panoles')->restrictOnDelete();
            $table->string('tipo_prestamo', 50);
            $table->foreignId('estudiante_id')->nullable()->constrained('estudiantes')->restrictOnDelete();
            $table->foreignId('profesor_id')->nullable()->constrained('usuarios')->restrictOnDelete();
            $table->foreignId('despachado_por_id')->nullable()->constrained('usuarios')->restrictOnDelete();
            $table->foreignId('recibido_por_id')->nullable()->constrained('usuarios')->restrictOnDelete();
            $table->string('estado', 50);
            $table->timestamp('fecha_solicitud')->useCurrent();
            $table->timestamp('fecha_hora_prestamo')->nullable();
            $table->timestamp('fecha_hora_devolucion_prevista')->nullable();
            $table->timestamp('fecha_hora_devolucion_real')->nullable();
            $table->text('observaciones')->nullable();

            $table->index(['panol_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prestamos');
    }
};
