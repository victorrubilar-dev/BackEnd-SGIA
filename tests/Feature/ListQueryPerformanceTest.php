<?php

namespace Tests\Feature;

use App\Models\Cajon;
use App\Models\ChartTemplate;
use App\Models\EquipmentUnit;
use App\Models\IncidentReport;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Location;
use App\Models\MaintenanceDocument;
use App\Models\MaintenanceRecord;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Quotation;
use App\Models\StockAlert;
use App\Models\Student;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserChart;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Con varios registros por listado, `Model::preventLazyLoading()` (activo fuera
 * de producción) lanza una excepción ante cualquier consulta N+1.
 */
class ListQueryPerformanceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_list_endpoints_do_not_trigger_lazy_loading(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);

        $location = Location::factory()->create();
        $cajon = Cajon::factory()->create(['location_id' => $location->id]);
        $supplier = Supplier::factory()->create();

        $products = Product::factory()->count(3)->create([
            'supplier_id' => $supplier->id,
            'cajon_id' => $cajon->id,
            'location_id' => $location->id,
        ]);

        foreach ($products as $product) {
            StockAlert::create([
                'product_id' => $product->id,
                'alert_type' => StockAlert::TYPE_WARNING,
                'current_stock' => 8,
                'stock_minimo' => 5,
                'message' => 'Advertencia',
                'is_resolved' => false,
            ]);
            IncidentReport::factory()->create(['product_id' => $product->id]);

            $loan = Loan::factory()->create();
            LoanItem::create([
                'loan_id' => $loan->id,
                'product_id' => $product->id,
                'name' => $product->name,
                'quantity' => 1,
            ]);

            $purchase = Purchase::factory()->create(['supplier_id' => $supplier->id, 'created_by' => $admin->id]);
            $purchase->items()->create(['name' => 'Item', 'quantity' => 2, 'unit_price' => 100]);

            EquipmentUnit::factory()->count(2)->create(['product_id' => $product->id]);

            $quotation = Quotation::factory()->create(['created_by' => $admin->id]);
            $quotation->items()->create(['product_id' => $product->id, 'name' => $product->name, 'quantity' => 1]);
            $quotation->suppliers()->create(['supplier_id' => $supplier->id]);
        }

        // Modelos de REQ-15 a REQ-20 y auditoría.
        $student = Student::factory()->create();
        $units = EquipmentUnit::where('product_id', $products[0]->id)->get();
        foreach ($units as $unit) {
            Loan::factory()->create(['student_id' => $student->id]);
            $record = MaintenanceRecord::factory()->create(['equipment_unit_id' => $unit->id, 'external_supplier_id' => $supplier->id, 'execution_mode' => 'externa']);
            MaintenanceDocument::create([
                'equipment_unit_id' => $unit->id, 'maintenance_record_id' => $record->id, 'uploaded_by' => $admin->id,
                'document_type' => 'manual', 'title' => 'Manual', 'file_path' => 'maintenance-documents/x.pdf',
            ]);
        }
        $template = ChartTemplate::factory()->create();
        foreach ([1, 2, 3] as $position) {
            UserChart::factory()->create(['user_id' => $admin->id, 'chart_template_id' => $template->id, 'position' => $position]);
        }

        Sanctum::actingAs($admin);

        $unitId = $units->first()->id;
        foreach ([
            '/api/students', "/api/students/{$student->id}/loans", '/api/maintenance-records',
            "/api/equipment-units/{$unitId}/maintenance-records", "/api/equipment-units/{$unitId}/maintenance-documents",
            '/api/chart-templates', '/api/me/charts', '/api/storerooms', '/api/audit-logs', '/api/roles',
            '/api/incident-reports', '/api/careers', '/api/categories',
        ] as $uri) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($uri)->assertOk();
            // Sin N+1: unas pocas consultas fijas (auth, conteo, página, eager loads).
            $this->assertLessThan(20, count(DB::getQueryLog()), "Demasiadas consultas en {$uri}");
            DB::disableQueryLog();
        }

        foreach ([
            '/api/products', '/api/products?storeroom_id='.$location->storeroom_id, '/api/suppliers', '/api/locations', '/api/cajones',
            '/api/purchases', '/api/quotations', '/api/loans', '/api/loans/pending',
            '/api/alerts/critical-stock', '/api/users', '/api/tokens',
            "/api/equipment/{$products[0]->id}/reports",
            "/api/products/{$products[0]->id}/units", '/api/equipment-units?search=EQP&maintenance_due=1&status=disponible',
        ] as $uri) {
            $this->getJson($uri)->assertOk();
        }
    }

    /**
     * REQ-NF-04: índices de los filtros frecuentes.
     */
    public function test_frequent_filters_are_indexed(): void
    {
        $indexed = fn (string $table, string $columns) => collect(DB::select('select indexdef from pg_indexes where tablename = ?', [$table]))
            ->contains(fn ($row) => str_contains($row->indexdef, "({$columns})"));

        foreach ([
            ['products', 'barcode'], ['products', 'area'], ['loans', 'status'], ['purchases', 'status'],
            ['users', 'role_id'], ['maintenance_records', 'status, started_at'], ['audit_logs', 'auditable_type, auditable_id'],
            ['maintenance_documents', 'equipment_unit_id, document_type'],
        ] as [$table, $columns]) {
            $this->assertTrue($indexed($table, $columns), "Falta índice en {$table}({$columns})");
        }
    }
}
