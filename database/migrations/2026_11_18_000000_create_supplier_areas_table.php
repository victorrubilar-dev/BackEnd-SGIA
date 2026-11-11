<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Áreas de proveedor (lista fija). `suppliers.category` (texto libre) se mapea por palabra clave;
     * lo que no calza queda sin área (no se inventan áreas) y se corrige a mano. La columna se mantiene
     * nullable un release.
     */
    public function up(): void
    {
        Schema::create('supplier_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->string('area', 30);
            $table->timestamps();
            $table->unique(['supplier_id', 'area']);
            $table->index('area');
        });
        DB::statement("ALTER TABLE supplier_areas ADD CONSTRAINT supplier_areas_area_check CHECK (area IN ('informatica','diseno','telecomunicaciones','ciberseguridad'))");

        $keywords = [
            'informatica' => ['informát', 'informat', 'computa', 'tecnolog'],
            'diseno' => ['diseñ', 'disen', 'gráfic', 'grafic'],
            'telecomunicaciones' => ['telecom', 'redes', 'networking'],
            'ciberseguridad' => ['ciber', 'seguridad'],
        ];
        $now = now();

        foreach (DB::table('suppliers')->whereNotNull('category')->get(['id', 'category']) as $supplier) {
            $category = mb_strtolower($supplier->category);

            foreach ($keywords as $area => $needles) {
                foreach ($needles as $needle) {
                    if (str_contains($category, $needle)) {
                        DB::table('supplier_areas')->insertOrIgnore([
                            'supplier_id' => $supplier->id, 'area' => $area, 'created_at' => $now, 'updated_at' => $now,
                        ]);
                        break;
                    }
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_areas');
    }
};
