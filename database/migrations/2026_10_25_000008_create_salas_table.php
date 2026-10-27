<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('panol_id')->constrained('panoles')->restrictOnDelete();
            $table->string('nombre_codigo', 100);
            $table->string('edificio', 10)->nullable();
            $table->integer('piso')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salas');
    }
};
