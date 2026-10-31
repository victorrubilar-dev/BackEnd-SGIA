<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ROLES = [
        'AD-01' => 'Administrador',
        'DIR-01' => 'Director de Carrera',
        'PAN-01' => 'Pañolero',
        'PRO-01' => 'Docente',
    ];

    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name', 100);
            $table->timestamps();
        });

        Schema::create('storerooms', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('code', 50)->unique();
            $table->string('general_location')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $now = now();
        foreach (self::ROLES as $code => $name) {
            DB::table('roles')->insert(['code' => $code, 'name' => $name, 'created_at' => $now, 'updated_at' => $now]);
        }
        DB::table('storerooms')->insert([
            'name' => 'Pañol Temuco',
            'code' => 'PAN-TEMUCO',
            'general_location' => 'Sede Temuco',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->constrained('roles')->restrictOnDelete();
            $table->foreignId('storeroom_id')->nullable()->constrained('storerooms')->nullOnDelete();
        });

        DB::statement('UPDATE users SET role_id = (SELECT id FROM roles WHERE roles.code = users.role)');
        // Roles desconocidos (datos antiguos) quedan como docente.
        DB::statement("UPDATE users SET role_id = (SELECT id FROM roles WHERE code = 'PRO-01') WHERE role_id IS NULL");
        DB::statement('ALTER TABLE users ALTER COLUMN role_id SET NOT NULL');
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('storeroom_id');
            $table->dropConstrainedForeignId('role_id');
        });
        Schema::dropIfExists('storerooms');
        Schema::dropIfExists('roles');
    }
};
