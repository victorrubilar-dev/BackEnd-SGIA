<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Cotizaciones recibidas (Fase 4): archivo subido por DIR-01, total y precio por ítem; la elegida. */
    public function up(): void
    {
        Schema::create('purchase_request_quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained('purchase_requests')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->string('file_path');
            $table->string('original_name')->nullable();
            $table->decimal('total', 15, 2);
            $table->timestamp('received_at');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['purchase_request_id', 'supplier_id']);
        });

        Schema::create('purchase_request_quotation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained('purchase_request_quotations')->cascadeOnDelete();
            $table->foreignId('purchase_request_item_id')->constrained('purchase_request_items')->cascadeOnDelete();
            $table->decimal('unit_price', 15, 2);
            $table->timestamps();
            $table->unique(['quotation_id', 'purchase_request_item_id']);
        });

        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->foreignId('selected_quotation_id')->nullable()->after('sent_at')
                ->constrained('purchase_request_quotations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('selected_quotation_id');
        });
        Schema::dropIfExists('purchase_request_quotation_items');
        Schema::dropIfExists('purchase_request_quotations');
    }
};
