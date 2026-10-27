<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('categoria_id')->constrained('categorias')->restrictOnDelete();
            $table->foreignId('carrera_id')->constrained('carreras')->restrictOnDelete();
            $table->string('sku', 100)->unique();
            $table->string('nombre', 150);
            $table->string('tipo', 50);
            $table->char('prioridad_mantencion', 1)->default('C');
            $table->boolean('requiere_calibracion')->default(false);
            $table->integer('frecuencia_mantencion_meses')->nullable();
            $table->decimal('precio_referencia', 15, 2)->nullable();
            $table->string('foto_url', 255)->nullable();
            $table->integer('stock_actual')->default(0);
            $table->integer('stock_minimo')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index('prioridad_mantencion');
        });

        DB::statement("ALTER TABLE productos ADD CONSTRAINT chk_productos_prioridad CHECK (prioridad_mantencion IN ('A', 'B', 'C'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('productos');
    }
};
