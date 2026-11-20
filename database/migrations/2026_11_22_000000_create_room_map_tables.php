<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mapa de salas (REQ-22, `docs/mapa-salas.md`): medidas de la sala, zonas, estantes y cajón → estante.
 * Medidas y posiciones en centímetros enteros desde la esquina superior izquierda de la sala.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->unsignedInteger('map_width_cm')->nullable();
            $table->unsignedInteger('map_height_cm')->nullable();
        });

        Schema::create('zonas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->string('nombre', 60);
            $table->unsignedInteger('x');
            $table->unsignedInteger('y');
            $table->unsignedInteger('width');
            $table->unsignedInteger('depth');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['location_id', 'nombre']);
        });

        Schema::create('estantes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('zona_id')->nullable()->constrained('zonas')->nullOnDelete();
            $table->string('codigo', 12);
            $table->string('nombre', 80)->nullable();
            $table->text('descripcion')->nullable();
            $table->unsignedInteger('x');
            $table->unsignedInteger('y');
            $table->unsignedInteger('width');
            $table->unsignedInteger('depth');
            $table->unsignedSmallInteger('rotation')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['location_id', 'codigo']);
        });

        Schema::table('cajones', function (Blueprint $table) {
            $table->foreignId('estante_id')->nullable()->constrained('estantes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cajones', function (Blueprint $table) {
            $table->dropConstrainedForeignId('estante_id');
        });
        Schema::dropIfExists('estantes');
        Schema::dropIfExists('zonas');
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn(['map_width_cm', 'map_height_cm']);
        });
    }
};
