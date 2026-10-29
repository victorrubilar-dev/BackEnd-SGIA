<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            // Fecha comprometida de devolución; por defecto, el día del préstamo.
            $table->date('due_date')->nullable()->index();
            // Se completa cuando todos los ítems fueron devueltos.
            $table->timestamp('returned_at')->nullable()->index();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('return_notes')->nullable();
        });

        Schema::table('loan_items', function (Blueprint $table) {
            $table->timestamp('returned_at')->nullable();
            // bueno | regular | danado | extraviado
            $table->string('return_condition')->nullable();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('quantity_in_repair')->default(0);
            $table->unsignedInteger('quantity_written_off')->default(0);
        });

        DB::table('loans')->whereNull('due_date')->update(['due_date' => DB::raw('loan_date')]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['quantity_in_repair', 'quantity_written_off']);
        });

        Schema::table('loan_items', function (Blueprint $table) {
            $table->dropColumn(['returned_at', 'return_condition']);
        });

        Schema::table('loans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('returned_by');
            $table->dropColumn(['due_date', 'returned_at', 'return_notes']);
        });
    }
};
