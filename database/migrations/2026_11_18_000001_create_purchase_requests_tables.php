<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Solicitud de compra (nuevo flujo de compras, Fase 2): código `SC-AAAA-NNNN`, productos y cantidades.
     * `yearly_code_sequences` numera por prefijo y año (el secuenciador de códigos de barra es por carrera).
     */
    public function up(): void
    {
        Schema::create('yearly_code_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('prefix', 5);
            $table->unsignedSmallInteger('year');
            $table->integer('current_index')->default(0);
            $table->timestamps();
            $table->unique(['prefix', 'year']);
        });

        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('status', 20)->default('borrador')->index();
            $table->foreignId('annual_budget_id')->constrained('annual_budgets')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE purchase_requests ADD CONSTRAINT purchase_requests_status_check CHECK (status IN ('borrador','aprobada','enviada','cotizada','con_oc','facturada','cancelada'))");

        Schema::create('purchase_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained('purchase_requests')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();
            $table->unique(['purchase_request_id', 'product_id']);
        });
        DB::statement('ALTER TABLE purchase_request_items ADD CONSTRAINT purchase_request_items_quantity_check CHECK (quantity > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_request_items');
        Schema::dropIfExists('purchase_requests');
        Schema::dropIfExists('yearly_code_sequences');
    }
};
