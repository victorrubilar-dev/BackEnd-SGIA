<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suscripciones de reportes por correo (docs/suscripcion-reportes.md). No se guarda el período: se calcula en cada envío.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('report_id', 60);
            $table->string('title', 120);
            $table->string('path', 120);
            $table->jsonb('params');
            $table->string('params_hash', 64);
            // Filtros en texto legible (sin el período) para «Mis suscripciones».
            $table->jsonb('filters');
            $table->string('frequency', 10);
            $table->boolean('is_active')->default(true);
            $table->string('paused_reason', 200)->nullable();
            $table->string('last_period', 10)->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->string('last_status', 10)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'report_id', 'params_hash', 'frequency']);
            $table->index(['is_active', 'frequency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_subscriptions');
    }
};
