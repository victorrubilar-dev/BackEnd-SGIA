<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotation_suppliers', function (Blueprint $table) {
            $table->timestamp('email_sent_at')->nullable();
            $table->string('email_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('quotation_suppliers', function (Blueprint $table) {
            $table->dropColumn(['email_sent_at', 'email_error']);
        });
    }
};
