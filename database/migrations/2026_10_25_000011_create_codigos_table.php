<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('codigos', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nombre', 20);
            $table->string('prefijo', 3);
            $table->string('carrera', 3);
            $table->integer('indice')->default(0);

            $table->unique(['prefijo', 'carrera']);
        });

        DB::statement("ALTER TABLE codigos ADD CONSTRAINT chk_codigos_carrera CHECK (carrera IN ('ISD', 'II', 'IC', 'AP'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('codigos');
    }
};
