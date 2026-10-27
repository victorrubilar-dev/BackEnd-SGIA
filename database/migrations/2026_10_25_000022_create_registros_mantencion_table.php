<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registros_mantencion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipo_id')->constrained('equipos')->restrictOnDelete();
            $table->foreignId('responsable_id')->constrained('usuarios')->restrictOnDelete();
            $table->foreignId('proveedor_externo_id')->nullable()->constrained('proveedores')->nullOnDelete();
            $table->foreignId('informe_novedad_id')->nullable()->constrained('informes_novedades')->nullOnDelete();
            $table->string('tipo_mantencion', 50);
            $table->string('modalidad_ejecucion', 30)->default('MEDIOS_PROPIOS');
            $table->boolean('rev_a_aprobada')->default(false);
            $table->boolean('rev_b_aprobada')->default(false);
            $table->boolean('rev_c_aprobada')->nullable();
            $table->boolean('pfg_aprobada')->default(false);
            $table->boolean('requiere_mantencion_adicional')->default(false);
            $table->boolean('aplica_recf')->default(false);
            $table->decimal('costo_reparacion', 15, 2)->default(0.00);
            $table->text('repuestos_utilizados')->nullable();
            $table->text('observaciones_tecnicas')->nullable();
            $table->string('estado', 50);
            $table->timestamp('fecha_inicio')->useCurrent();
            $table->timestamp('fecha_termino')->nullable();

            $table->index(['equipo_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registros_mantencion');
    }
};
