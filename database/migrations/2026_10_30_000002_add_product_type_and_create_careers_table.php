<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // equipo | insumo. Los productos existentes quedan como equipo.
        Schema::table('products', function (Blueprint $table) {
            $table->string('type', 20)->default('equipo')->index();
        });

        // Catálogo de carreras: `users.area` se une por nombre para conocer el nivel.
        Schema::create('careers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            // tecnico | profesional
            $table->string('level', 30)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('careers');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
