<?php

namespace Tests\Feature;

use App\Models\AnnualBudget;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesPurchases;
use Tests\Concerns\ReadsXlsx;
use Tests\TestCase;

/**
 * Reporte Excel de órdenes de compra `GET /api/purchases/export` (docs/reportes-excel.md).
 */
class PurchaseExportTest extends TestCase
{
    use CreatesPurchases;
    use DatabaseTransactions;
    use ReadsXlsx;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_exports_orders_and_items_with_the_list_filters(): void
    {
        $director = $this->actingAsRole(User::ROLE_DIRECTOR);
        $tag = 'OCX'.fake()->unique()->numerify('#####');
        $budget = AnnualBudget::factory()->create();
        $supplier = Supplier::factory()->create(['name' => "{$tag} Proveedor", 'rut' => '76.086.428-5']);
        $product = Product::factory()->supply()->create(['name' => "{$tag} Mouse"]);
        $first = $this->reservedPurchase($budget, [
            ['product_id' => $product->id, 'name' => $product->name, 'quantity' => 3, 'unit_price' => 5000],
            ['name' => "{$tag} Teclado", 'quantity' => 1, 'unit_price' => 12000],
        ], $supplier, $director);
        $second = $this->reservedPurchase($budget, [['name' => "{$tag} Monitor", 'quantity' => 2, 'unit_price' => 90000]], $supplier, $director);
        $this->reservedPurchase($budget, [['name' => 'Otro', 'quantity' => 1, 'unit_price' => 1000]], null, $director);

        $response = $this->get("/api/purchases/export?supplier_id={$supplier->id}")->assertOk();
        $this->assertMatchesRegularExpression('/filename="ordenes-compra-\d{8}-\d{6}\.xlsx"/', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame(['Órdenes', 'Ítems', 'Info'], array_keys($this->readXlsx($response)));

        $orders = collect($this->xlsxRecords($response, 'Órdenes'))->keyBy('OC');
        $this->assertEqualsCanonicalizing([$first->code, $second->code], $orders->keys()->all());
        $this->assertSame("{$tag} Proveedor", $orders[$first->code]['Proveedor']);
        $this->assertSame('Pendiente', $orders[$first->code]['Estado']);
        $this->assertSame(27000, $orders[$first->code]['Total']);
        $this->assertSame(2, $orders[$first->code]['Ítems']);
        $this->assertSame($budget->career->name, $orders[$first->code]['Carrera']);

        $items = collect($this->xlsxRecords($response, 'Ítems'));
        $this->assertCount(3, $items);
        $mouse = $items->firstWhere('Producto', "{$tag} Mouse");
        $this->assertSame($first->code, $mouse['OC']);
        $this->assertSame(3, $mouse['Cantidad']);
        $this->assertSame(15000, $mouse['Subtotal']);
        $this->assertSame("Proveedor: {$tag} Proveedor", $this->xlsxInfo($response)['Filtros']);
    }

    public function test_status_and_search_filters_exclude_orders(): void
    {
        $director = $this->actingAsRole(User::ROLE_DIRECTOR);
        $budget = AnnualBudget::factory()->create();
        $supplier = Supplier::factory()->create();
        $pending = $this->reservedPurchase($budget, [['name' => 'A', 'quantity' => 1, 'unit_price' => 1000]], $supplier, $director);
        $transit = $this->reservedPurchase($budget, [['name' => 'B', 'quantity' => 1, 'unit_price' => 1000]], $supplier, $director);
        $transit->update(['status' => Purchase::STATUS_IN_TRANSIT]);

        $response = $this->get("/api/purchases/export?supplier_id={$supplier->id}&estado=en_camino")->assertOk();
        $this->assertSame([$transit->code], array_column($this->xlsxRecords($response, 'Órdenes'), 'OC'));
        $this->assertStringContainsString('Estado: En camino', $this->xlsxInfo($response)['Filtros']);

        $response = $this->get("/api/purchases/export?search={$pending->code}")->assertOk();
        $this->assertSame([$pending->code], array_column($this->xlsxRecords($response, 'Órdenes'), 'OC'));
        $this->assertSame(['A'], array_column($this->xlsxRecords($response, 'Ítems'), 'Producto'));
    }

    public function test_roles_and_validation(): void
    {
        foreach ([User::ROLE_ADMIN, User::ROLE_WAREHOUSE, User::ROLE_TEACHER] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/purchases/export')->assertForbidden();
        }

        $this->actingAsRole(User::ROLE_DIRECTOR);
        $this->getJson('/api/purchases/export?status=perdida')->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->getJson('/api/purchases/export?supplier_id=abc')->assertUnprocessable()->assertJsonValidationErrors('supplier_id');
    }
}
