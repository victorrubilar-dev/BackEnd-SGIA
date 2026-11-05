<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Las unidades ya etiquetadas conservan su código `PRO-…`; solo los nuevos salen `EQP-…`. */
    public function up(): void
    {
        DB::table('code_sequences')->where('prefix', 'PRO')->update(['prefix' => 'EQP', 'name' => 'equipos']);
    }

    public function down(): void
    {
        DB::table('code_sequences')->where('prefix', 'EQP')->update(['prefix' => 'PRO', 'name' => 'productos']);
    }
};
