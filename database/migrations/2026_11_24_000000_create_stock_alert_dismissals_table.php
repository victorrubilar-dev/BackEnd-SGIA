<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alertas de stock que cada usuario quitó de su campana (docs/exportaciones-y-campana.md).
 * Solo las oculta para esa persona: la alerta sigue activa en /alertas y para los demás.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_alert_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_alert_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['user_id', 'stock_alert_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_alert_dismissals');
    }
};
