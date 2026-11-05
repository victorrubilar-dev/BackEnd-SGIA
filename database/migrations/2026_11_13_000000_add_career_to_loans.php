<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Carrera a la que pertenece el préstamo (se elige al pedirlo; los anteriores quedan sin carrera). */
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->foreignId('career_id')->nullable()->after('subject')->constrained('careers')->nullOnDelete();
            $table->index('career_id');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('career_id');
        });
    }
};
