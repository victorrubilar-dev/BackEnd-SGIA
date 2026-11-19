<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Los estudiantes no se borran (auditoría): se desactivan.
        Schema::table('students', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('career_id');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
