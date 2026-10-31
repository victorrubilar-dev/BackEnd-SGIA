<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('careers', function (Blueprint $table) {
            $table->string('code', 3)->nullable()->unique();
        });
        DB::statement("ALTER TABLE careers ADD CONSTRAINT careers_code_check CHECK (code IN ('ISD','II','IC','AP'))");

        Schema::create('career_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('career_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'career_id']);
        });

        DB::statement(<<<'SQL'
            INSERT INTO career_user (user_id, career_id, created_at, updated_at)
            SELECT u.id, c.id, NOW(), NOW()
            FROM users u
            JOIN careers c ON LOWER(c.name) = LOWER(u.area)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('career_user');
        DB::statement('ALTER TABLE careers DROP CONSTRAINT IF EXISTS careers_code_check');
        Schema::table('careers', function (Blueprint $table) {
            $table->dropColumn('code');
        });
    }
};
