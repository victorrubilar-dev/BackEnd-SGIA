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
        Schema::create('stock_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('alert_type'); // 'warning' | 'critical'
            $table->unsignedInteger('current_stock');
            $table->unsignedInteger('stock_minimo');
            $table->string('message');
            $table->boolean('is_resolved')->default(false);
            $table->timestamps();

            $table->index(['product_id', 'is_resolved']);
            $table->index(['alert_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_alerts');
    }
};
