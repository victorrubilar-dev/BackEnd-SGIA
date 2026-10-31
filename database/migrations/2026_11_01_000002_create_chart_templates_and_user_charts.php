<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->string('visualization_type', 50);
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('user_charts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chart_template_id')->constrained('chart_templates')->restrictOnDelete();
            $table->string('custom_title', 150);
            $table->string('type', 30);
            $table->integer('position');
            $table->string('entity', 50);
            $table->string('metric', 50);
            $table->string('group_by', 50);
            $table->string('period', 50);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE user_charts ADD CONSTRAINT user_charts_position_check CHECK (position BETWEEN 1 AND 5)');
        DB::statement('ALTER TABLE user_charts ADD CONSTRAINT user_charts_user_position_unique UNIQUE (user_id, position) DEFERRABLE INITIALLY DEFERRED');
    }

    public function down(): void
    {
        Schema::dropIfExists('user_charts');
        Schema::dropIfExists('chart_templates');
    }
};
