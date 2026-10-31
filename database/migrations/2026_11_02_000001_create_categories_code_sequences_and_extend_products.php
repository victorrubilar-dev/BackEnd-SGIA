<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('code_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('name', 20);
            $table->string('prefix', 3);
            $table->string('career', 3);
            $table->integer('current_index')->default(0);
            $table->timestamps();
            $table->unique(['prefix', 'career']);
        });
        DB::statement("ALTER TABLE code_sequences ADD CONSTRAINT code_sequences_career_check CHECK (career IN ('ISD','II','IC','AP'))");

        $now = now();
        foreach (['MAQ' => 'maquetas', 'INS' => 'insumos', 'PRO' => 'productos'] as $prefix => $name) {
            foreach (['ISD', 'II', 'IC', 'AP'] as $career) {
                DB::table('code_sequences')->insert([
                    'name' => $name, 'prefix' => $prefix, 'career' => $career,
                    'current_index' => 0, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fn_generar_codigo_sgia(p_prefijo VARCHAR(3), p_carrera VARCHAR(3))
            RETURNS VARCHAR AS $$
            DECLARE
                v_indice INT;
            BEGIN
                UPDATE code_sequences
                   SET current_index = current_index + 1, updated_at = NOW()
                 WHERE prefix = p_prefijo AND career = p_carrera
             RETURNING current_index INTO v_indice;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'No existe secuenciador para el prefijo % y la carrera %', p_prefijo, p_carrera;
                END IF;

                RETURN p_prefijo || '-' || p_carrera || '-' || LPAD(v_indice::TEXT, 6, '0');
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->foreignId('career_id')->nullable()->constrained('careers')->restrictOnDelete();
            $table->char('maintenance_priority', 1)->default('C')->index();
            $table->boolean('requires_calibration')->default(false);
            $table->integer('maintenance_frequency_months')->nullable();
            $table->decimal('reference_price', 15, 2)->nullable();
        });
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_maintenance_priority_check CHECK (maintenance_priority IN ('A','B','C'))");

        // Una categoría por cada valor distinto de `products.area` ("Sin categoría" para los nulos).
        $areas = DB::table('products')->select('area')->distinct()->pluck('area');
        foreach ($areas as $area) {
            $name = ($area === null || trim($area) === '') ? 'Sin categoría' : mb_substr($area, 0, 100);
            $id = DB::table('categories')->where('name', $name)->value('id')
                ?? DB::table('categories')->insertGetId(['name' => $name, 'created_at' => $now, 'updated_at' => $now]);
            $query = DB::table('products');
            ($area === null || trim($area) === '') ? $query->where(fn ($q) => $q->whereNull('area')->orWhere('area', '')) : $query->where('area', $area);
            $query->update(['category_id' => $id]);
        }
        if (DB::table('products')->whereNull('category_id')->doesntExist()) {
            DB::statement('ALTER TABLE products ALTER COLUMN category_id SET NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
            $table->dropConstrainedForeignId('career_id');
            $table->dropColumn(['maintenance_priority', 'requires_calibration', 'maintenance_frequency_months', 'reference_price']);
        });
        DB::unprepared('DROP FUNCTION IF EXISTS fn_generar_codigo_sgia(VARCHAR, VARCHAR)');
        Schema::dropIfExists('code_sequences');
        Schema::dropIfExists('categories');
    }
};
