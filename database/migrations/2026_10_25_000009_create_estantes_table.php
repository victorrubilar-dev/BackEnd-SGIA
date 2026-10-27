<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estantes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sala_id')->constrained('salas')->restrictOnDelete();
            $table->string('nombre', 100);
            $table->string('codigo_barras', 100)->unique();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estantes');
    }
};
