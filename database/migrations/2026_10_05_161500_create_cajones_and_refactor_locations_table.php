<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Agregar columnas created_by y updated_by a locations si no existen,
        // y renombrar/adaptar columnas.
        Schema::table('locations', function (Blueprint $table) {
            $table->string('nombre')->nullable()->after('id');
            $table->string('tipo')->default('sala')->after('nombre'); // sala, panol, etc.
            $table->foreignId('created_by')->nullable()->after('descripcion')->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
        });

        // Poblar nombre y tipo a partir de los datos existentes de sala
        DB::statement("UPDATE locations SET nombre = sala WHERE nombre IS NULL");

        // 2. Crear tabla cajones
        Schema::create('cajones', function (Blueprint $table) {
            $table->id();
            $table->string('codigo'); // ej. "Cajon-01", "Gaveta-B2"
            $table->string('descripcion')->nullable();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['location_id', 'codigo']);
        });

        // 3. Migrar datos existentes: para cada location (que tenía sala y cajon), crear el cajon correspondiente
        $existingLocations = DB::table('locations')->get();
        foreach ($existingLocations as $loc) {
            if (! empty($loc->cajon)) {
                DB::table('cajones')->insert([
                    'codigo' => $loc->cajon,
                    'descripcion' => $loc->descripcion,
                    'location_id' => $loc->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // 4. Agregar cajon_id a products
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('cajon_id')->nullable()->after('supplier_id')->constrained('cajones')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->after('is_active')->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
        });

        // Vincular productos existentes a su cajón migrado
        $cajones = DB::table('cajones')->get();
        foreach ($cajones as $cajon) {
            DB::table('products')
                ->where('location_id', $cajon->location_id)
                ->update(['cajon_id' => $cajon->id]);
        }

        // 5. Ajustar restricciones en locations
        Schema::table('locations', function (Blueprint $table) {
            // Eliminar restricción unique antigua (sala, cajon)
            $table->dropUnique(['sala', 'cajon']);
            $table->string('sala')->nullable()->change();
            $table->string('cajon')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cajon_id');
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('updated_by');
        });

        Schema::dropIfExists('cajones');

        Schema::table('locations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropColumn(['nombre', 'tipo']);
            $table->unique(['sala', 'cajon']);
        });
    }
};
