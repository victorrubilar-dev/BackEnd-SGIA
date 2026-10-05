<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique()->index();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            // Máquina de estados: pendiente -> en_camino -> completa
            $table->string('status')->default('pendiente')->index();
            $table->string('quotation_reference')->nullable();
            $table->date('expected_at')->nullable();
            $table->decimal('total', 12, 2)->nullable();
            $table->text('notes')->nullable();
            // Datos registrados al escanear la guía de despacho / factura de llegada
            $table->string('guide_number')->nullable();
            $table->string('invoice_number')->nullable();
            $table->string('arrival_document')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
