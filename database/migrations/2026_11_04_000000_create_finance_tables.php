<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('annual_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_id')->constrained('careers')->restrictOnDelete();
            $table->integer('year');
            $table->decimal('initial_amount', 15, 2);
            $table->decimal('available_amount', 15, 2);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['career_id', 'year']);
        });
        DB::statement('ALTER TABLE annual_budgets ADD CONSTRAINT annual_budgets_available_check CHECK (available_amount >= 0)');

        Schema::create('invoice_intakes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('annual_budget_id')->constrained('annual_budgets')->restrictOnDelete();
            $table->string('pdf_path')->nullable();
            $table->string('document_number', 100)->nullable();
            $table->decimal('total_deducted', 15, 2)->nullable();
            $table->jsonb('raw_ocr_payload')->nullable();
            // borrador | confirmado | anulado
            $table->string('status', 50)->nullable();
            $table->timestamp('received_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('invoice_intake_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_intake_id')->constrained('invoice_intakes')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('detected_name')->nullable();
            $table->integer('quantity');
            $table->decimal('unit_price', 15, 2)->nullable();
            $table->decimal('subtotal', 15, 2)->nullable();
            $table->timestamps();
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('rut', 20)->nullable()->unique();
            $table->string('address')->nullable();
        });

        Schema::table('quotations', function (Blueprint $table) {
            $table->foreignId('career_id')->nullable()->constrained('careers')->restrictOnDelete();
            $table->string('title', 150)->nullable();
        });

        Schema::table('quotation_suppliers', function (Blueprint $table) {
            $table->string('quotation_number', 100)->nullable();
            $table->string('backup_file_path')->nullable();
            // MODELS.MD: cotizaciones.proveedor_id es RESTRICT.
            $table->dropForeign(['supplier_id']);
            $table->foreign('supplier_id')->references('id')->on('suppliers')->restrictOnDelete();
        });

        Schema::create('quotation_offer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_supplier_id')->constrained('quotation_suppliers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->integer('requested_quantity');
            $table->decimal('offered_unit_price', 15, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_offer_items');
        Schema::table('quotation_suppliers', function (Blueprint $table) {
            $table->dropForeign(['supplier_id']);
            $table->foreign('supplier_id')->references('id')->on('suppliers')->cascadeOnDelete();
            $table->dropColumn(['quotation_number', 'backup_file_path']);
        });
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('career_id');
            $table->dropColumn('title');
        });
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn(['rut', 'address']);
        });
        Schema::dropIfExists('invoice_intake_items');
        Schema::dropIfExists('invoice_intakes');
        Schema::dropIfExists('annual_budgets');
    }
};
