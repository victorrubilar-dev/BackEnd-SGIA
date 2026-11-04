<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `users.role_id` (FK a `roles`) pasa a ser la única fuente del rol; se retira `users.role`.
 * La API sigue recibiendo y devolviendo el código (`AD-01`…) mediante el atributo `User::role`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Por si quedó algún usuario sin sincronizar.
        DB::statement('UPDATE users SET role_id = roles.id FROM roles WHERE roles.code = users.role AND users.role_id IS DISTINCT FROM roles.id');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
            $table->index('role_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role_id']);
            $table->string('role')->default('PRO-01');
        });

        DB::statement('UPDATE users SET role = roles.code FROM roles WHERE roles.id = users.role_id');
    }
};
