<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Envío por áreas (Fase 3): áreas elegidas y proveedores contactados con el estado de su correo. */
    public function up(): void
    {
        Schema::create('purchase_request_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained('purchase_requests')->cascadeOnDelete();
            $table->string('area', 30);
            $table->timestamps();
            $table->unique(['purchase_request_id', 'area']);
        });
        DB::statement("ALTER TABLE purchase_request_areas ADD CONSTRAINT purchase_request_areas_area_check CHECK (area IN ('informatica','diseno','telecomunicaciones','ciberseguridad'))");

        Schema::create('purchase_request_suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained('purchase_requests')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->timestamp('email_sent_at')->nullable();
            $table->string('email_error', 255)->nullable();
            $table->timestamps();
            $table->unique(['purchase_request_id', 'supplier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_request_suppliers');
        Schema::dropIfExists('purchase_request_areas');
    }
};
