<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cajones llenos (`docs/cajones-llenos.md`): estado que marca una persona; informativo, no bloquea nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cajones', function (Blueprint $table) {
            $table->boolean('lleno')->default(false)->after('estante_id');
        });
    }

    public function down(): void
    {
        Schema::table('cajones', function (Blueprint $table) {
            $table->dropColumn('lleno');
        });
    }
};
