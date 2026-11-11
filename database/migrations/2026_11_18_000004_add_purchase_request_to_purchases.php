<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** OC subida desde la solicitud (Fase 5). Las compras históricas quedan con `purchase_request_id` null. */
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->foreignId('purchase_request_id')->nullable()->after('quotation_id')
                ->constrained('purchase_requests')->nullOnDelete();
            $table->string('order_number', 100)->nullable()->after('purchase_request_id');
            $table->string('order_file_path')->nullable()->after('order_number');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_request_id');
            $table->dropColumn(['order_number', 'order_file_path']);
        });
    }
};
