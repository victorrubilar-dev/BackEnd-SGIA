<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Motivo cuando la compra se hace con menos del mínimo recomendado de proveedores que respondieron. */
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->text('quotation_justification')->nullable()->after('quotation_reference');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn('quotation_justification');
        });
    }
};
