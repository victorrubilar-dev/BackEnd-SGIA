<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingreso_ocr_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingreso_ocr_id')->constrained('ingresos_ocr')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->restrictOnDelete();
            $table->string('nombre_detectado', 255)->nullable();
            $table->integer('cantidad');
            $table->decimal('precio_unitario', 15, 2)->nullable();
            $table->decimal('subtotal', 15, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingreso_ocr_detalles');
    }
};
