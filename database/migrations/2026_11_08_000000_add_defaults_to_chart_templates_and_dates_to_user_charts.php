<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-15: cada plantilla trae una configuración por defecto (la hereda el gráfico del usuario
 * si no la envía); un gráfico puede ir sin `group_by` (serie en el tiempo) y con período CUSTOM.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chart_templates', function (Blueprint $table) {
            $table->string('chart_type', 30)->nullable()->after('visualization_type');
            $table->string('entity', 50)->nullable()->after('chart_type');
            $table->string('metric', 50)->nullable()->after('entity');
            $table->string('group_by', 50)->nullable()->after('metric');
            $table->string('period', 50)->nullable()->after('group_by');
        });

        Schema::table('user_charts', function (Blueprint $table) {
            $table->string('group_by', 50)->nullable()->change();
            $table->date('date_from')->nullable()->after('period');
            $table->date('date_to')->nullable()->after('date_from');
        });
    }

    public function down(): void
    {
        Schema::table('user_charts', function (Blueprint $table) {
            $table->dropColumn(['date_from', 'date_to']);
        });

        Schema::table('chart_templates', function (Blueprint $table) {
            $table->dropColumn(['chart_type', 'entity', 'metric', 'group_by', 'period']);
        });
    }
};
