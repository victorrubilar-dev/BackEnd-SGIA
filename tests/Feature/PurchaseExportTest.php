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
use Tests\Unit\XlsxWriterTest;

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
        $this->assertSame(['Órdenes', 'Ítems', 'Resumen', 'Info'], array_keys($this->readXlsx($response)));

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

    public function test_period_by_creation_date_summary_by_status_and_supplier_and_totals(): void
    {
        $director = $this->actingAsRole(User::ROLE_DIRECTOR);
        $tag = 'OCP'.fake()->unique()->numerify('#####');
        $budget = AnnualBudget::factory()->create();
        $supplier = Supplier::factory()->create(['name' => "{$tag} Acme"]);
        $march = $this->reservedPurchase($budget, [['name' => "{$tag} A", 'quantity' => 2, 'unit_price' => 10000]], $supplier, $director);
        $endOfMarch = $this->reservedPurchase($budget, [['name' => "{$tag} B", 'quantity' => 1, 'unit_price' => 5000]], $supplier, $director);
        $april = $this->reservedPurchase($budget, [['name' => "{$tag} C", 'quantity' => 1, 'unit_price' => 7000]], $supplier, $director);
        $march->forceFill(['created_at' => '2026-03-02 09:00:00'])->save();
        $endOfMarch->forceFill(['created_at' => '2026-03-31 23:00:00', 'status' => Purchase::STATUS_IN_TRANSIT])->save();
        $april->forceFill(['created_at' => '2026-04-01 08:00:00'])->save();

        $response = $this->get("/api/purchases/export?supplier_id={$supplier->id}&from=2026-03-01&to=2026-03-31")->assertOk();
        $this->assertEqualsCanonicalizing([$march->code, $endOfMarch->code], array_column($this->xlsxRecords($response, 'Órdenes'), 'OC'));
        $this->assertStringContainsString('Creada desde: 2026-03-01; Creada hasta: 2026-03-31', $this->xlsxInfo($response)['Filtros']);

        $summary = $this->xlsxSummary($response);
        $this->assertContains('Estado|Pendiente|1|20000', $summary);
        $this->assertContains('Estado|En camino|1|5000', $summary);
        $this->assertContains("Proveedor|{$tag} Acme|2|25000", $summary);

        $this->assertSame(['OC' => 'Total', 'Total' => 25000, 'Reservado' => 25000], $this->xlsxTotals($response, 'Órdenes'));
        $this->assertSame(['OC' => 'Total', 'Subtotal' => 25000], $this->xlsxTotals($response, 'Ítems'));
        $xml = XlsxWriterTest::unzip((string) $response->getContent());
        $this->assertStringContainsString('<f>SUBTOTAL(109,I2:I3)</f><v>25000</v>', $xml['xl/worksheets/sheet1.xml']);
        $this->assertStringContainsString('<f>SUBTOTAL(109,J2:J3)</f><v>25000</v>', $xml['xl/worksheets/sheet1.xml']);
        $this->assertStringContainsString('<f>SUBTOTAL(109,G2:G3)</f><v>25000</v>', $xml['xl/worksheets/sheet2.xml']);

        $listed = $this->getJson("/api/purchases?supplier_id={$supplier->id}&from=2026-04-01")->assertOk()->json('data');
        $this->assertSame([$april->code], array_column($listed, 'code'));
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
        $this->getJson('/api/purchases/export?from=2026-03-31&to=2026-03-01')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson('/api/purchases?from=2026-03-31&to=2026-03-01')->assertUnprocessable()->assertJsonValidationErrors('to');
    }
}
