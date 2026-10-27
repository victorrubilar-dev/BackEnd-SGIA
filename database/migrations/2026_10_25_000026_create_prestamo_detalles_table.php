<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prestamo_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prestamo_id')->constrained('prestamos')->cascadeOnDelete();
            $table->foreignId('equipo_id')->constrained('equipos')->restrictOnDelete();
            $table->integer('cantidad_solicitada');
            $table->integer('cantidad_entregada')->default(0);
            $table->integer('cantidad_devuelta')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prestamo_detalles');
    }
};
