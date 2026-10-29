<?php

namespace Tests\Feature;

use App\Models\Cajon;
use App\Models\IncidentReport;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Location;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Quotation;
use App\Models\StockAlert;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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

            $quotation = Quotation::factory()->create(['created_by' => $admin->id]);
            $quotation->items()->create(['product_id' => $product->id, 'name' => $product->name, 'quantity' => 1]);
            $quotation->suppliers()->create(['supplier_id' => $supplier->id]);
        }

        Sanctum::actingAs($admin);

        foreach ([
            '/api/products', '/api/suppliers', '/api/locations', '/api/cajones',
            '/api/purchases', '/api/quotations', '/api/loans', '/api/loans/pending',
            '/api/alerts/critical-stock', '/api/users', '/api/tokens',
            "/api/equipment/{$products[0]->id}/reports",
        ] as $uri) {
            $this->getJson($uri)->assertOk();
        }
    }
}
