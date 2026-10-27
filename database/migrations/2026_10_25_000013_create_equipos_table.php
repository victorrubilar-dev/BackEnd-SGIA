<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos')->restrictOnDelete();
            $table->foreignId('estante_id')->constrained('estantes')->restrictOnDelete();
            $table->string('codigo_barras', 100)->unique();
            $table->string('numero_serie', 100)->unique()->nullable();
            $table->string('estado', 50)->default('DISPONIBLE');
            $table->timestamp('fecha_ultima_mantencion')->nullable();
            $table->timestamp('fecha_proxima_mantencion')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['producto_id', 'estado']);
        });

        // Partial index for fecha_proxima_mantencion
        \Illuminate\Support\Facades\DB::statement(
            'CREATE INDEX idx_equipos_prox_mantencion ON equipos(fecha_proxima_mantencion) WHERE fecha_proxima_mantencion IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('equipos');
    }
};
