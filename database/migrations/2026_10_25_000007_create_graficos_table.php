<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('graficos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->foreignId('plantilla_id')->constrained('plantillas_grafico')->restrictOnDelete();
            $table->string('titulo_personalizado', 150);
            $table->string('tipo', 30);
            $table->integer('posicion');
            $table->string('entidad', 50);
            $table->string('metrica', 50);
            $table->string('agrupado_por', 50);
            $table->string('lapso_tiempo', 50);
            $table->boolean('activo')->default(true);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['usuario_id', 'posicion']);
        });

        DB::statement('ALTER TABLE graficos ADD CONSTRAINT chk_graficos_posicion CHECK (posicion >= 1 AND posicion <= 5)');
    }

    public function down(): void
    {
        Schema::dropIfExists('graficos');
    }
};
