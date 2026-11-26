<?php

namespace Tests\Feature;

use App\Models\AnnualBudget;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\PurchaseRequestQuotation;
use App\Models\PurchaseRequestQuotationItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ReadsXlsx;
use Tests\TestCase;

/**
 * Reporte Excel de solicitudes de compra `GET /api/purchase-requests/export` (docs/reportes-excel.md).
 */
class PurchaseRequestExportTest extends TestCase
{
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

    /**
     * @return array{0: PurchaseRequest, 1: PurchaseRequest, 2: Product}
     */
    private function requests(string $tag, User $director): array
    {
        $budget = AnnualBudget::factory()->create();
        $product = Product::factory()->supply()->create(['name' => "{$tag} Cable"]);
        $supplier = Supplier::factory()->create(['name' => "{$tag} Proveedor"]);

        $ordered = PurchaseRequest::create([
            'code' => "{$tag}-0001", 'status' => PurchaseRequest::STATUS_ORDERED, 'annual_budget_id' => $budget->id,
            'created_by' => $director->id, 'approved_by' => $director->id, 'approved_at' => now()->subDays(3), 'sent_at' => now()->subDays(2),
        ]);
        $item = PurchaseRequestItem::create(['purchase_request_id' => $ordered->id, 'product_id' => $product->id, 'quantity' => 2]);
        $quotation = PurchaseRequestQuotation::create([
            'purchase_request_id' => $ordered->id, 'supplier_id' => $supplier->id, 'file_path' => 'x.pdf', 'total' => 2000, 'received_at' => now(),
        ]);
        PurchaseRequestQuotationItem::create(['quotation_id' => $quotation->id, 'purchase_request_item_id' => $item->id, 'unit_price' => 1000]);
        $ordered->update(['selected_quotation_id' => $quotation->id]);
        Purchase::factory()->create(['purchase_request_id' => $ordered->id, 'supplier_id' => $supplier->id, 'annual_budget_id' => $budget->id, 'total' => 2380, 'order_number' => "EXT-{$tag}"]);

        $draft = PurchaseRequest::create(['code' => "{$tag}-0002", 'status' => PurchaseRequest::STATUS_DRAFT, 'annual_budget_id' => $budget->id, 'created_by' => $director->id]);
        PurchaseRequestItem::create(['purchase_request_id' => $draft->id, 'product_id' => $product->id, 'quantity' => 5]);

        return [$ordered, $draft, $product];
    }

    public function test_exports_requests_and_items_with_the_list_filters(): void
    {
        $director = $this->actingAsRole(User::ROLE_DIRECTOR);
        $tag = 'SCX'.fake()->unique()->numerify('#####');
        [$ordered, , $product] = $this->requests($tag, $director);

        $response = $this->get("/api/purchase-requests/export?code={$tag}")->assertOk();
        $this->assertMatchesRegularExpression('/filename="solicitudes-compra-\d{8}-\d{6}\.xlsx"/', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame(['Solicitudes', 'Ítems', 'Resumen', 'Info'], array_keys($this->readXlsx($response)));

        $requests = collect($this->xlsxRecords($response, 'Solicitudes'))->keyBy('Código');
        $this->assertSame(["{$tag}-0002", "{$tag}-0001"], $requests->keys()->all());
        $row = $requests["{$tag}-0001"];
        $this->assertSame('Con OC', $row['Estado']);
        $this->assertSame($ordered->annualBudget->career->name, $row['Carrera']);
        $this->assertSame("{$tag} Proveedor", $row['Proveedor elegido']);
        $this->assertSame(2380, $row['Total']);
        $this->assertSame("EXT-{$tag}", $row['N° OC proveedor']);
        $this->assertSame(1, $row['Ítems']);
        $this->assertIsNumeric($row['Aprobada']);
        $this->assertSame('Borrador', $requests["{$tag}-0002"]['Estado']);

        $items = collect($this->xlsxRecords($response, 'Ítems'))->keyBy('Solicitud');
        $this->assertCount(2, $items);
        $this->assertSame($product->name, $items["{$tag}-0001"]['Producto']);
        $this->assertSame(2, $items["{$tag}-0001"]['Cantidad']);
        $this->assertSame(1000, $items["{$tag}-0001"]['Precio unitario']);
        $this->assertSame(2000, $items["{$tag}-0001"]['Total']);
        $this->assertArrayNotHasKey('Precio unitario', $items["{$tag}-0002"]);

        $this->assertSame("Código: {$tag}", $this->xlsxInfo($response)['Filtros']);

        $summary = $this->xlsxSummary($response);
        $this->assertContains('Estado|Con OC|1|2380', $summary);
        $this->assertContains('Estado|Borrador|1|0', $summary);
        $this->assertContains('Carrera|'.$ordered->annualBudget->career->name.'|2|2380', $summary);
        $this->assertSame(['Código' => 'Total', 'Total' => 2380], $this->xlsxTotals($response, 'Solicitudes'));
        $this->assertSame(['Solicitud' => 'Total', 'Total' => 2000], $this->xlsxTotals($response, 'Ítems'));
    }

    public function test_period_by_creation_date_excludes_requests(): void
    {
        $director = $this->actingAsRole(User::ROLE_DIRECTOR);
        $tag = 'SCP'.fake()->unique()->numerify('#####');
        [$ordered, $draft] = $this->requests($tag, $director);
        $ordered->forceFill(['created_at' => '2026-03-31 23:00:00'])->save();
        $draft->forceFill(['created_at' => '2026-04-01 00:30:00'])->save();

        $response = $this->get("/api/purchase-requests/export?code={$tag}&from=2026-03-01&to=2026-03-31")->assertOk();
        $this->assertSame(["{$tag}-0001"], array_column($this->xlsxRecords($response, 'Solicitudes'), 'Código'));
        $this->assertSame(["{$tag}-0001"], array_column($this->xlsxRecords($response, 'Ítems'), 'Solicitud'));
    }

    public function test_status_filter_excludes_other_requests(): void
    {
        $director = $this->actingAsRole(User::ROLE_DIRECTOR);
        $tag = 'SCY'.fake()->unique()->numerify('#####');
        $this->requests($tag, $director);

        $response = $this->get("/api/purchase-requests/export?code={$tag}&status=borrador")->assertOk();
        $this->assertSame(["{$tag}-0002"], array_column($this->xlsxRecords($response, 'Solicitudes'), 'Código'));
        $this->assertSame(["{$tag}-0002"], array_column($this->xlsxRecords($response, 'Ítems'), 'Solicitud'));
        $this->assertStringContainsString('Estado: Borrador', $this->xlsxInfo($response)['Filtros']);
    }

    public function test_roles_and_validation(): void
    {
        foreach ([User::ROLE_ADMIN, User::ROLE_WAREHOUSE, User::ROLE_TEACHER] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/purchase-requests/export')->assertForbidden();
        }

        $this->actingAsRole(User::ROLE_DIRECTOR);
        $this->getJson('/api/purchase-requests/export?status=perdida')->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->getJson('/api/purchase-requests/export?from=2026-11-10&to=2026-11-01')->assertUnprocessable()->assertJsonValidationErrors('to');
    }
}
