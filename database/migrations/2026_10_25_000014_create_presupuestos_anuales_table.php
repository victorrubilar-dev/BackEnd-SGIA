<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('presupuestos_anuales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carrera_id')->constrained('carreras')->restrictOnDelete();
            $table->integer('anio');
            $table->decimal('monto_inicial', 15, 2);
            $table->decimal('monto_disponible', 15, 2);
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['carrera_id', 'anio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presupuestos_anuales');
    }
};
