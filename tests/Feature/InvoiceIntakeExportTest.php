<?php

namespace Tests\Feature;

use App\Models\AnnualBudget;
use App\Models\InvoiceIntake;
use App\Models\InvoiceIntakeItem;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ReadsXlsx;
use Tests\TestCase;

/**
 * Reporte Excel de facturas `GET /api/invoice-intakes/export` (docs/reportes-excel.md).
 */
class InvoiceIntakeExportTest extends TestCase
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
     * @return array{0: AnnualBudget, 1: InvoiceIntake, 2: InvoiceIntake, 3: string}
     */
    private function invoices(User $director): array
    {
        $tag = 'FAC'.fake()->unique()->numerify('#####');
        $budget = AnnualBudget::factory()->create();
        $supplier = Supplier::factory()->create(['name' => "{$tag} Proveedor"]);
        $request = PurchaseRequest::create(['code' => "SC-{$tag}", 'status' => PurchaseRequest::STATUS_INVOICED, 'annual_budget_id' => $budget->id, 'created_by' => $director->id]);
        $purchase = Purchase::factory()->create(['supplier_id' => $supplier->id, 'annual_budget_id' => $budget->id, 'purchase_request_id' => $request->id]);

        $confirmed = InvoiceIntake::factory()->create([
            'user_id' => $director->id, 'annual_budget_id' => $budget->id, 'purchase_id' => $purchase->id,
            'document_number' => "{$tag}-1", 'status' => InvoiceIntake::STATUS_CONFIRMED, 'total_deducted' => 119000, 'received_at' => now()->subDay(),
        ]);
        $product = Product::factory()->supply()->create(['name' => "{$tag} Toner"]);
        InvoiceIntakeItem::create(['invoice_intake_id' => $confirmed->id, 'product_id' => $product->id, 'detected_name' => 'TONER HP 85A', 'quantity' => 4, 'unit_price' => 25000, 'subtotal' => 100000]);

        $draft = InvoiceIntake::factory()->create([
            'user_id' => $director->id, 'annual_budget_id' => $budget->id, 'document_number' => "{$tag}-2",
            'raw_ocr_payload' => ['supplier_name' => 'Leído por OCR'],
        ]);

        return [$budget, $confirmed, $draft, $tag];
    }

    public function test_exports_invoices_and_items_with_the_list_filters(): void
    {
        $director = $this->actingAsRole(User::ROLE_DIRECTOR);
        [$budget, $confirmed, $draft, $tag] = $this->invoices($director);
        InvoiceIntake::factory()->create(['user_id' => $director->id, 'annual_budget_id' => AnnualBudget::factory()->create()->id]);

        $response = $this->get("/api/invoice-intakes/export?annual_budget_id={$budget->id}")->assertOk();
        $this->assertMatchesRegularExpression('/filename="facturas-\d{8}-\d{6}\.xlsx"/', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame(['Facturas', 'Ítems', 'Info'], array_keys($this->readXlsx($response)));

        $invoices = collect($this->xlsxRecords($response, 'Facturas'))->keyBy('Folio');
        $this->assertEqualsCanonicalizing(["{$tag}-1", "{$tag}-2"], $invoices->keys()->all());
        $row = $invoices["{$tag}-1"];
        $this->assertSame("{$tag} Proveedor", $row['Proveedor']);
        $this->assertSame($confirmed->purchase->code, $row['OC']);
        $this->assertSame("SC-{$tag}", $row['Solicitud']);
        $this->assertSame(119000, $row['Total']);
        $this->assertSame('Confirmado', $row['Estado']);
        $this->assertSame('Leído por OCR', $invoices["{$tag}-2"]['Proveedor']);
        $this->assertSame('Borrador', $invoices["{$tag}-2"]['Estado']);

        $items = $this->xlsxRecords($response, 'Ítems');
        $this->assertCount(1, $items);
        $this->assertSame("{$tag} Toner", $items[0]['Producto']);
        $this->assertSame('TONER HP 85A', $items[0]['Texto en la factura']);
        $this->assertSame(100000, $items[0]['Subtotal']);

        $this->assertStringStartsWith('Presupuesto: ', $this->xlsxInfo($response)['Filtros']);
    }

    public function test_status_purchase_and_request_code_filters_exclude_invoices(): void
    {
        $director = $this->actingAsRole(User::ROLE_DIRECTOR);
        [$budget, $confirmed, , $tag] = $this->invoices($director);

        foreach (["status=confirmado&annual_budget_id={$budget->id}", "purchase_id={$confirmed->purchase_id}", "purchase_request_code=SC-{$tag}"] as $query) {
            $response = $this->get("/api/invoice-intakes/export?{$query}")->assertOk();
            $this->assertSame(["{$tag}-1"], array_column($this->xlsxRecords($response, 'Facturas'), 'Folio'), $query);
        }
        $this->assertStringContainsString("Solicitud: SC-{$tag}", $this->xlsxInfo($response)['Filtros']);
    }

    public function test_roles_and_validation(): void
    {
        foreach ([User::ROLE_ADMIN, User::ROLE_WAREHOUSE, User::ROLE_TEACHER] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/invoice-intakes/export')->assertForbidden();
        }

        $this->actingAsRole(User::ROLE_DIRECTOR);
        $this->getJson('/api/invoice-intakes/export?status=pagada')->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->getJson('/api/invoice-intakes/export?purchase_id=abc')->assertUnprocessable()->assertJsonValidationErrors('purchase_id');
        $this->getJson('/api/invoice-intakes/export?from=fecha')->assertUnprocessable()->assertJsonValidationErrors('from');
    }
}
