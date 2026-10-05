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
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique()->index();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            // Máquina de estados: pendiente -> aceptada / rechazada -> convertida
            $table->string('status')->default('pendiente')->index();
            $table->text('notes')->nullable();
            $table->date('expires_at')->nullable();
            // Vínculo con la orden de compra generada (REQ-07 <-> REQ-08)
            $table->foreignId('purchase_id')->nullable()->constrained('purchases')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quotations');
    }
};
