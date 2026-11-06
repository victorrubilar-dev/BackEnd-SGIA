<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // `available` ya no baja al reservar (= inicial − consumido) y puede quedar negativo si DIR-01 autoriza un exceso.
    public function up(): void
    {
        DB::statement('ALTER TABLE annual_budgets DROP CONSTRAINT IF EXISTS annual_budgets_available_check');
        DB::statement('UPDATE annual_budgets SET available_amount = available_amount + reserved_amount');
    }

    public function down(): void
    {
        DB::statement('UPDATE annual_budgets SET available_amount = available_amount - reserved_amount');
        DB::statement('ALTER TABLE annual_budgets ADD CONSTRAINT annual_budgets_available_check CHECK (available_amount >= 0)');
    }
};
