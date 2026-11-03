<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('annual_budgets', function (Blueprint $table) {
            $table->decimal('reserved_amount', 15, 2)->default(0)->after('available_amount');
        });
        DB::statement('ALTER TABLE annual_budgets ADD CONSTRAINT annual_budgets_reserved_check CHECK (reserved_amount >= 0)');
        DB::statement('ALTER TABLE annual_budgets ADD CONSTRAINT annual_budgets_initial_reserved_check CHECK (initial_amount - reserved_amount >= 0)');

        Schema::create('budget_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('annual_budget_id')->constrained('annual_budgets')->restrictOnDelete();
            // asignacion_inicial | ajuste | reserva | liberacion_reserva | consumo | reposicion
            $table->string('type', 30);
            $table->decimal('amount', 15, 2);
            $table->unsignedBigInteger('purchase_id')->nullable();
            $table->foreignId('invoice_intake_id')->nullable()->constrained('invoice_intakes')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['annual_budget_id', 'created_at']);
        });
        DB::statement('ALTER TABLE budget_movements ADD CONSTRAINT budget_movements_amount_check CHECK (amount >= 0)');

        // REQ-22: la compra reserva presupuesto y la factura puede ligarse a una compra.
        Schema::table('purchases', function (Blueprint $table) {
            $table->foreignId('annual_budget_id')->nullable()->after('quotation_reference')->constrained('annual_budgets')->restrictOnDelete();
            $table->decimal('reserved_amount', 15, 2)->default(0)->after('total');
        });

        Schema::table('invoice_intakes', function (Blueprint $table) {
            $table->foreignId('purchase_id')->nullable()->after('annual_budget_id')->constrained('purchases')->nullOnDelete();
        });

        Schema::table('budget_movements', function (Blueprint $table) {
            $table->foreign('purchase_id')->references('id')->on('purchases')->nullOnDelete();
        });

        // REQ-23: trazabilidad de las unidades creadas por una factura.
        Schema::table('equipment_units', function (Blueprint $table) {
            $table->foreignId('invoice_intake_item_id')->nullable()->constrained('invoice_intake_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('equipment_units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_intake_item_id');
        });
        Schema::table('budget_movements', function (Blueprint $table) {
            $table->dropForeign(['purchase_id']);
        });
        Schema::table('invoice_intakes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_id');
        });
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('annual_budget_id');
            $table->dropColumn('reserved_amount');
        });
        Schema::dropIfExists('budget_movements');
        DB::statement('ALTER TABLE annual_budgets DROP CONSTRAINT IF EXISTS annual_budgets_initial_reserved_check');
        DB::statement('ALTER TABLE annual_budgets DROP CONSTRAINT IF EXISTS annual_budgets_reserved_check');
        Schema::table('annual_budgets', function (Blueprint $table) {
            $table->dropColumn('reserved_amount');
        });
    }
};
