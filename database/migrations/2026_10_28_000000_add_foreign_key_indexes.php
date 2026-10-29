<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// PostgreSQL no indexa las claves foráneas por sí solo; estas se usan en filtros y joins.
return new class extends Migration
{
    /** @var array<string, array<int, string>> */
    private array $indexes = [
        'products' => ['supplier_id', 'location_id', 'cajon_id'],
        'purchases' => ['supplier_id', 'quotation_id', 'created_by'],
        'purchase_items' => ['purchase_id', 'product_id'],
        'quotation_items' => ['quotation_id', 'product_id'],
        'quotation_suppliers' => ['quotation_id', 'supplier_id'],
        'loans' => ['requested_by'],
        'loan_items' => ['loan_id', 'product_id'],
        'incident_reports' => ['product_id'],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns) {
                foreach ($columns as $column) {
                    $blueprint->index($column);
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns) {
                foreach ($columns as $column) {
                    $blueprint->dropIndex([$column]);
                }
            });
        }
    }
};
