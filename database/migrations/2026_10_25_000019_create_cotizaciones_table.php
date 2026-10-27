<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cotizaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proceso_cotizacion_id')->constrained('procesos_cotizacion')->cascadeOnDelete();
            $table->foreignId('proveedor_id')->constrained('proveedores')->restrictOnDelete();
            $table->string('numero_cotizacion', 100)->nullable();
            $table->string('archivo_respaldo_url', 255)->nullable();
            $table->decimal('monto_total', 15, 2)->nullable();
            $table->string('estado', 50);
            $table->timestamp('fecha_envio')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cotizaciones');
    }
};
