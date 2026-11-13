<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-15: plantillas de dashboard con nombre creadas por el usuario. Cada plantilla agrupa hasta
 * 9 gráficos (`user_charts.position` 1–9); los gráficos sin plantilla forman el panel principal.
 * La unicidad pasa de (user, position) a (user, plantilla, position).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('description')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'name']);
        });

        Schema::table('user_charts', function (Blueprint $table) {
            $table->foreignId('dashboard_template_id')->nullable()->after('chart_template_id')
                ->constrained('dashboard_templates')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE user_charts DROP CONSTRAINT user_charts_position_check');
        DB::statement('ALTER TABLE user_charts ADD CONSTRAINT user_charts_position_check CHECK (position BETWEEN 1 AND 9)');
        DB::statement('ALTER TABLE user_charts DROP CONSTRAINT user_charts_user_position_unique');
        // NULLS NOT DISTINCT: el panel principal (plantilla nula) también es único por posición.
        DB::statement('ALTER TABLE user_charts ADD CONSTRAINT user_charts_user_position_unique UNIQUE NULLS NOT DISTINCT (user_id, dashboard_template_id, position) DEFERRABLE INITIALLY DEFERRED');
    }

    public function down(): void
    {
        DB::statement('DELETE FROM user_charts WHERE dashboard_template_id IS NOT NULL OR position > 5');
        DB::statement('ALTER TABLE user_charts DROP CONSTRAINT user_charts_user_position_unique');
        DB::statement('ALTER TABLE user_charts ADD CONSTRAINT user_charts_user_position_unique UNIQUE (user_id, position) DEFERRABLE INITIALLY DEFERRED');
        DB::statement('ALTER TABLE user_charts DROP CONSTRAINT user_charts_position_check');
        DB::statement('ALTER TABLE user_charts ADD CONSTRAINT user_charts_position_check CHECK (position BETWEEN 1 AND 5)');

        Schema::table('user_charts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dashboard_template_id');
        });
        Schema::dropIfExists('dashboard_templates');
    }
};
