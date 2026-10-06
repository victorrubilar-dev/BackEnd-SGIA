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
        Schema::create('incident_reports', function (Blueprint $table) {
            $table->id();
            // Informes de novedades y fallas asociados al equipo (REQ-13)
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('code')->unique()->index();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title')->nullable();
            $table->text('description');
            // leve | media | critica
            $table->string('severity')->default('media')->index();
            // reportado | en_revision | en_reparacion | reparado | dado_de_baja
            $table->string('status')->default('reportado')->index();
            // Adjunto opcional (documento o imagen) almacenado en disco público
            $table->string('attachment')->nullable();
            $table->string('attachment_original_name')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incident_reports');
    }
};
