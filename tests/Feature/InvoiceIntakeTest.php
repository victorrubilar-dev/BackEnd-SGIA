<?php

namespace Tests\Feature;

use App\Models\AnnualBudget;
use App\Models\Cajon;
use App\Models\EquipmentUnit;
use App\Models\InvoiceIntake;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsProductPayload;
use Tests\TestCase;

class InvoiceIntakeTest extends TestCase
{
    use BuildsProductPayload;
    use DatabaseTransactions;

    private const INVOICE_TEXT = <<<'TXT'
Comercial ElectroChile S.A.            R.U.T.: 76.123.456-7
FACTURA ELECTRONICA N° 4521

Descripcion                     Cant      Precio        Total
Osciloscopio Digital 100MHz        5     250.000    1.250.000
TXT;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function draft(): InvoiceIntake
    {
        return InvoiceIntake::factory()->create([
            'pdf_path' => UploadedFile::fake()->create('f.pdf', 10, 'application/pdf')->store('invoice-intakes', 'local'),
        ]);
    }

    public function test_upload_creates_draft_with_ocr_payload_and_private_file(): void
    {
        Process::fake(['*pdftotext*' => Process::result(self::INVOICE_TEXT)]);
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/invoice-intakes', [
            'file' => UploadedFile::fake()->create('factura.pdf', 100, 'application/pdf'),
        ])->assertCreated()
            ->assertJsonPath('data.status', 'borrador')
            ->assertJsonPath('data.document_number', '4521')
            ->assertJsonPath('data.raw_ocr_payload.items.0.name', 'Osciloscopio Digital 100MHz')
            ->assertJsonPath('data.has_file', true);

        $intake = InvoiceIntake::findOrFail($response->json('data.id'));
        Storage::disk('local')->assertExists($intake->pdf_path);
        $this->assertSame($this->admin->id, $intake->user_id);
        $this->assertSame(0, $intake->items()->count());
    }

    public function test_upload_without_ocr_still_creates_empty_draft(): void
    {
        Process::fake(fn () => Process::result('', 'pdftotext: not found', 127));
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/invoice-intakes', [
            'file' => UploadedFile::fake()->create('factura.pdf', 100, 'application/pdf'),
        ])->assertCreated()->assertJsonPath('data.status', 'borrador');
    }

    public function test_upload_validation(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/invoice-intakes', [])
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->postJson('/api/invoice-intakes', [
            'file' => UploadedFile::fake()->create('x.txt', 10, 'text/plain'),
        ])->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_confirm_receives_stock_and_leaves_budgets_untouched(): void
    {
        $budget = AnnualBudget::factory()->create(['initial_amount' => 1000000, 'available_amount' => 1000000]);
        $intake = $this->draft();
        $supply = Product::factory()->supply()->create(['quantity' => 3, 'stock_minimo' => 1]);
        $equipment = Product::factory()->create(array_merge($this->unitPayload(), ['quantity' => 0]));
        $cajon = Cajon::factory()->create();
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/invoice-intakes/{$intake->id}/confirm", [
            'document_number' => 'F-100',
            'items' => [
                ['product_id' => $supply->id, 'quantity' => 10, 'unit_price' => 1500, 'detected_name' => 'Cable'],
                ['product_id' => $equipment->id, 'quantity' => 2, 'unit_price' => 250000, 'cajon_id' => $cajon->id],
            ],
        ])->assertOk()
            ->assertJsonPath('data.status', 'confirmado')
            ->assertJsonPath('data.total_deducted', '515000.00')
            ->assertJsonCount(2, 'data.items');

        $this->assertSame('1000000.00', $budget->fresh()->available_amount);
        $this->assertNull($intake->fresh()->annual_budget_id);
        $this->assertSame(13, $supply->fresh()->quantity);
        $this->assertSame(2, EquipmentUnit::where('product_id', $equipment->id)->count());
        $this->assertSame(2, $equipment->fresh()->quantity);
        $this->assertDatabaseHas('invoice_intake_items', ['invoice_intake_id' => $intake->id, 'product_id' => $supply->id, 'subtotal' => '15000.00']);
    }

    private function purchaseFor(array $lines, array $attrs = []): Purchase
    {
        $purchase = Purchase::factory()->create($attrs);
        foreach ($lines as [$product, $qty]) {
            PurchaseItem::create(['purchase_id' => $purchase->id, 'product_id' => $product->id, 'name' => $product->name, 'quantity' => $qty, 'unit_price' => 100]);
        }

        return $purchase;
    }

    private function confirmPayload(array $lines, array $extra = []): array
    {
        return $extra + [
            'document_number' => 'F-200',
            'items' => array_map(fn ($l) => ['product_id' => $l[0]->id, 'quantity' => $l[1], 'unit_price' => 100], $lines),
        ];
    }

    public function test_confirm_auto_links_purchase_with_same_products_and_quantities(): void
    {
        [$a, $b] = [Product::factory()->supply()->create(), Product::factory()->supply()->create()];
        $match = $this->purchaseFor([[$a, 5], [$b, 2]]);
        $this->purchaseFor([[$a, 5], [$b, 3]]); // otra cantidad: no coincide
        $intake = $this->draft();
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/invoice-intakes/{$intake->id}/confirm", $this->confirmPayload([[$b, 2], [$a, 5]]))
            ->assertOk()->assertJsonPath('data.purchase_id', $match->id)
            ->assertJsonPath('data.linked', true)
            ->assertJsonPath('data.purchase.code', $match->code)
            ->assertJsonStructure(['data' => ['annual_budget', 'total_deducted']]);

        $this->assertSame(Purchase::STATUS_COMPLETED, $match->fresh()->status);
    }

    public function test_confirm_does_not_link_when_ambiguous(): void
    {
        $a = Product::factory()->supply()->create();
        $this->purchaseFor([[$a, 5]]);
        $this->purchaseFor([[$a, 5]]);
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/invoice-intakes/{$this->draft()->id}/confirm", $this->confirmPayload([[$a, 5]]))
            ->assertOk()->assertJsonPath('data.purchase_id', null)->assertJsonPath('data.linked', false);
    }

    public function test_confirm_ambiguity_is_resolved_by_invoice_number(): void
    {
        $a = Product::factory()->supply()->create();
        $this->purchaseFor([[$a, 5]]);
        $match = $this->purchaseFor([[$a, 5]], ['invoice_number' => 'F-200']);
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/invoice-intakes/{$this->draft()->id}/confirm", $this->confirmPayload([[$a, 5]]))
            ->assertOk()->assertJsonPath('data.purchase_id', $match->id);
    }

    public function test_confirm_accepts_explicit_purchase_id_and_validates_it(): void
    {
        $a = Product::factory()->supply()->create();
        $purchase = $this->purchaseFor([[$a, 9]]);
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/invoice-intakes/{$this->draft()->id}/confirm", $this->confirmPayload([[$a, 5]], ['purchase_id' => 999999]))
            ->assertStatus(422)->assertJsonValidationErrors('purchase_id');

        $this->postJson("/api/invoice-intakes/{$this->draft()->id}/confirm", $this->confirmPayload([[$a, 5]], ['purchase_id' => $purchase->id]))
            ->assertOk()->assertJsonPath('data.purchase_id', $purchase->id);
    }

    public function test_linking_completes_purchase_and_settles_its_budget(): void
    {
        $budget = AnnualBudget::factory()->create(['initial_amount' => 1000000, 'available_amount' => 1000000]);
        $product = Product::factory()->supply()->create();
        Sanctum::actingAs($this->admin);
        $purchaseId = $this->postJson('/api/purchases', [
            'annual_budget_id' => $budget->id,
            'supplier_id' => Supplier::factory()->create()->id,
            'items' => [['product_id' => $product->id, 'name' => 'x', 'quantity' => 5, 'unit_price' => 1000]],
        ])->assertCreated()->json('data.id');
        $this->assertSame('995000.00', $budget->fresh()->available_amount);

        $intake = $this->draft();
        $this->postJson("/api/invoice-intakes/{$intake->id}/confirm", [
            'document_number' => 'F-9',
            'items' => [['product_id' => $product->id, 'quantity' => 5, 'unit_price' => 1100]],
        ])->assertOk()->assertJsonPath('data.purchase_id', $purchaseId)
            ->assertJsonPath('data.purchase.status', 'completa')
            ->assertJsonPath('data.annual_budget.id', $budget->id)
            ->assertJsonPath('data.annual_budget.reserved', '0.00')
            ->assertJsonPath('data.annual_budget.available', '994500.00');

        $purchase = Purchase::find($purchaseId);
        $this->assertSame(Purchase::STATUS_COMPLETED, $purchase->status);
        $this->assertSame('F-9', $purchase->invoice_number);
        $this->assertSame('0.00', $purchase->reserved_amount);
        $budget->refresh();
        $this->assertSame('994500.00', $budget->available_amount);
        $this->assertSame('0.00', $budget->reserved_amount);

        $this->postJson("/api/invoice-intakes/{$intake->id}/cancel")->assertOk();
        $this->assertSame('1000000.00', $budget->fresh()->available_amount);
    }

    public function test_linking_rolls_back_when_invoice_exceeds_budget(): void
    {
        $budget = AnnualBudget::factory()->create(['initial_amount' => 10000, 'available_amount' => 10000]);
        $product = Product::factory()->supply()->create(['quantity' => 0]);
        Sanctum::actingAs($this->admin);
        $purchaseId = $this->postJson('/api/purchases', [
            'annual_budget_id' => $budget->id,
            'supplier_id' => Supplier::factory()->create()->id,
            'items' => [['product_id' => $product->id, 'name' => 'x', 'quantity' => 1, 'unit_price' => 5000]],
        ])->assertCreated()->json('data.id');

        $intake = $this->draft();
        $this->postJson("/api/invoice-intakes/{$intake->id}/confirm", [
            'document_number' => 'F-9',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50000]],
        ])->assertStatus(422);

        $this->assertSame(Purchase::STATUS_PENDING, Purchase::find($purchaseId)->status);
        $this->assertSame(InvoiceIntake::STATUS_DRAFT, $intake->fresh()->status);
        $this->assertSame(0, $product->fresh()->quantity);
    }

    public function test_explicit_purchase_must_be_linkable(): void
    {
        $a = Product::factory()->supply()->create();
        $cancelled = $this->purchaseFor([[$a, 1]], ['status' => Purchase::STATUS_CANCELLED]);
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/invoice-intakes/{$this->draft()->id}/confirm", $this->confirmPayload([[$a, 1]], ['purchase_id' => $cancelled->id]))
            ->assertStatus(422)->assertJsonValidationErrors('purchase_id');
    }

    public function test_confirm_twice_is_rejected(): void
    {
        $intake = $this->draft();
        $supply = Product::factory()->supply()->create(['quantity' => 3]);
        $payload = ['document_number' => 'F-1', 'items' => [['product_id' => $supply->id, 'quantity' => 1, 'unit_price' => 10]]];
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/invoice-intakes/{$intake->id}/confirm", $payload)->assertOk();
        $this->postJson("/api/invoice-intakes/{$intake->id}/confirm", $payload)->assertStatus(422)->assertJsonValidationErrors('status');
        $this->assertSame(4, $supply->fresh()->quantity);
    }

    public function test_confirm_validation_unknown_product_and_unit_without_cajon(): void
    {
        $intake = $this->draft();
        $equipment = Product::factory()->create($this->unitPayload());
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/invoice-intakes/{$intake->id}/confirm", [
            'document_number' => 'F-1',
            'items' => [['product_id' => 999999, 'quantity' => 1, 'unit_price' => 10]],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.product_id');

        $this->postJson("/api/invoice-intakes/{$intake->id}/confirm", [
            'document_number' => 'F-1',
            'items' => [['product_id' => $equipment->id, 'quantity' => 1, 'unit_price' => 10]],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.cajon_id');

        $this->postJson("/api/invoice-intakes/{$intake->id}/confirm", ['document_number' => 'F-1', 'items' => []])
            ->assertStatus(422)->assertJsonValidationErrors('items');

        $this->assertSame('borrador', $intake->fresh()->status);
    }

    public function test_cancel_draft_and_second_cancel_is_rejected(): void
    {
        $intake = $this->draft();
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/invoice-intakes/{$intake->id}/cancel")->assertOk()->assertJsonPath('data.status', 'anulado');
        $this->postJson("/api/invoice-intakes/{$intake->id}/cancel")->assertStatus(422);

    }

    public function test_list_filters_show_and_file_download(): void
    {
        $intake = $this->draft();
        $other = InvoiceIntake::factory()->create(['status' => InvoiceIntake::STATUS_CONFIRMED]);
        Sanctum::actingAs($this->admin);

        $ids = collect($this->getJson('/api/invoice-intakes?status=borrador')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($intake->id));
        $this->assertFalse($ids->contains($other->id));

        $this->getJson("/api/invoice-intakes/{$intake->id}")->assertOk()->assertJsonPath('data.id', $intake->id);
        $this->get("/api/invoice-intakes/{$intake->id}/file", ['Accept' => 'application/json'])->assertOk();
        $this->getJson("/api/invoice-intakes/{$other->id}/file")->assertNotFound();
    }

    public function test_roles_are_enforced_and_area_director_operates(): void
    {
        $intake = $this->draft();
        $file = UploadedFile::fake()->create('f.pdf', 10, 'application/pdf');

        foreach ([User::ROLE_WAREHOUSE, User::ROLE_TEACHER] as $role) {
            Sanctum::actingAs($this->user($role));
            $this->postJson('/api/invoice-intakes', ['file' => $file])->assertForbidden();
            $this->getJson('/api/invoice-intakes')->assertForbidden();
            $this->getJson("/api/invoice-intakes/{$intake->id}")->assertForbidden();
            $this->postJson("/api/invoice-intakes/{$intake->id}/cancel")->assertForbidden();
        }

        // DIR-01 es director de área: opera sin carreras asignadas.
        Sanctum::actingAs($this->user(User::ROLE_DIRECTOR));
        $this->assertNotEmpty($this->getJson('/api/invoice-intakes')->assertOk()->json('data'));
        $this->getJson("/api/invoice-intakes/{$intake->id}")->assertOk();
        $this->postJson("/api/invoice-intakes/{$intake->id}/cancel")->assertOk();
    }
}
