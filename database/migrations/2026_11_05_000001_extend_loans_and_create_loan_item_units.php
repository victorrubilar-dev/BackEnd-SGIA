<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->foreignId('storeroom_id')->nullable()->constrained('storerooms')->restrictOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->restrictOnDelete();
            $table->timestamp('expected_return_at')->nullable();
        });

        $storeroomId = DB::table('storerooms')->where('code', 'PAN-TEMUCO')->value('id');
        DB::table('loans')->whereNull('storeroom_id')->update(['storeroom_id' => $storeroomId]);
        DB::statement('ALTER TABLE loans ALTER COLUMN storeroom_id SET NOT NULL');
        // Fin del día de la fecha comprometida (`due_date` se mantiene por compatibilidad).
        DB::statement("UPDATE loans SET expected_return_at = COALESCE(due_date, loan_date)::timestamp + INTERVAL '1 day' - INTERVAL '1 second' WHERE expected_return_at IS NULL");
        Schema::table('loans', function (Blueprint $table) {
            $table->index(['storeroom_id', 'status']);
        });

        Schema::table('loan_items', function (Blueprint $table) {
            $table->unsignedInteger('delivered_quantity')->default(0);
            $table->unsignedInteger('returned_quantity')->default(0);
        });
        DB::statement("UPDATE loan_items SET delivered_quantity = quantity WHERE loan_id IN (SELECT id FROM loans WHERE status = 'procesado')");
        DB::statement('UPDATE loan_items SET returned_quantity = quantity WHERE returned_at IS NOT NULL');

        Schema::create('loan_item_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_item_id')->constrained('loan_items')->cascadeOnDelete();
            $table->foreignId('equipment_unit_id')->constrained('equipment_units')->restrictOnDelete();
            $table->timestamp('returned_at')->nullable();
            // bueno | regular | danado | extraviado
            $table->string('return_condition')->nullable();
            $table->timestamps();
            $table->unique(['loan_item_id', 'equipment_unit_id']);
            $table->index('equipment_unit_id');
        });

        $this->linkBorrowedUnits();
    }

    /**
     * Vincula las unidades `prestado` creadas por la migración de equipment_units
     * con los ítems de préstamo vigentes del mismo producto.
     */
    private function linkBorrowedUnits(): void
    {
        $items = DB::table('loan_items')
            ->join('loans', 'loans.id', '=', 'loan_items.loan_id')
            ->whereIn('loans.status', ['en_proceso', 'procesado'])
            ->whereNull('loan_items.returned_at')
            ->whereNotNull('loan_items.product_id')
            ->orderBy('loan_items.id')
            ->get(['loan_items.id', 'loan_items.product_id', 'loan_items.quantity']);

        $now = now();
        $linked = [];
        foreach ($items as $item) {
            $unitIds = DB::table('equipment_units')
                ->where('product_id', $item->product_id)
                ->where('status', 'prestado')
                ->whereNotIn('id', $linked ?: [0])
                ->orderBy('id')
                ->limit($item->quantity)
                ->pluck('id');

            foreach ($unitIds as $unitId) {
                DB::table('loan_item_units')->insert([
                    'loan_item_id' => $item->id,
                    'equipment_unit_id' => $unitId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $linked[] = $unitId;
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_item_units');
        Schema::table('loan_items', function (Blueprint $table) {
            $table->dropColumn(['delivered_quantity', 'returned_quantity']);
        });
        Schema::table('loans', function (Blueprint $table) {
            $table->dropIndex(['storeroom_id', 'status']);
            $table->dropConstrainedForeignId('student_id');
            $table->dropConstrainedForeignId('storeroom_id');
            $table->dropColumn('expected_return_at');
        });
    }
};
