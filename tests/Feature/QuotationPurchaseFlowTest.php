<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\Quotation;
use App\Models\QuotationSupplier;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Flujo completo REQ-07 (cotizaciones) -> REQ-08 (órdenes de compra).
 */
class QuotationPurchaseFlowTest extends TestCase
{
    use DatabaseTransactions;

    protected User $director;

    protected User $warehouse;

    protected Supplier $supplier;

    protected Supplier $otherSupplier;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->director = User::factory()->create([
            'email' => 'dir_flow_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_DIRECTOR,
            'is_active' => true,
        ]);

        $this->warehouse = User::factory()->create([
            'email' => 'panol_flow_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_WAREHOUSE,
            'is_active' => true,
        ]);

        $this->supplier = Supplier::factory()->create();
        $this->otherSupplier = Supplier::factory()->create();
        $this->product = Product::factory()->create();
    }

    /**
     * Crea una cotización contactando a 3 proveedores (2 extra + el otro).
     */
    protected function makeQuotation(string $status, array $attributes = []): Quotation
    {
        $extraSuppliers = Supplier::factory()->count(2)->create();

        $quotation = Quotation::factory()->create(array_merge([
            'created_by' => $this->director->id,
            'status' => $status,
        ], $attributes));

        $quotation->items()->create([
            'product_id' => $this->product->id,
            'name' => $this->product->name,
            'quantity' => 6,
        ]);

        foreach ([$this->supplier, $this->otherSupplier, ...$extraSuppliers] as $supplier) {
            $quotation->suppliers()->create(['supplier_id' => $supplier->id]);
        }

        return $quotation;
    }

    public function test_purchase_can_be_created_from_accepted_quotation(): void
    {
        $quotation = $this->makeQuotation(Quotation::STATUS_ACCEPTED);
        $quotation->suppliers()->where('supplier_id', $this->supplier->id)->update([
            'status' => QuotationSupplier::STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);

        Sanctum::actingAs($this->director);

        $response = $this->postJson('/api/purchases', [
            'quotation_id' => $quotation->id,
            'expected_at' => now()->addWeek()->toDateString(),
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.quotation_id', $quotation->id);
        $response->assertJsonPath('data.quotation_reference', $quotation->code);
        $response->assertJsonPath('data.supplier.id', $this->supplier->id);
        $response->assertJsonPath('data.items.0.name', $this->product->name);
        $response->assertJsonPath('data.items.0.quantity', 6);

        $purchaseId = $response->json('data.id');
        $this->assertDatabaseHas('purchases', [
            'id' => $purchaseId,
            'quotation_id' => $quotation->id,
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_PENDING,
        ]);

        // La cotización queda vinculada y convertida
        $quotation->refresh();
        $this->assertEquals(Quotation::STATUS_CONVERTED, $quotation->status);
        $this->assertEquals($purchaseId, $quotation->purchase_id);
        $this->assertNotNull($quotation->converted_at);

        // Trazabilidad en ambos sentidos
        $this->getJson('/api/purchases?quotation_id=' . $quotation->id)
            ->assertStatus(200)
            ->assertJsonFragment(['id' => $purchaseId]);

        $this->getJson('/api/quotations/' . $quotation->id)
            ->assertStatus(200)
            ->assertJsonPath('data.purchase.id', $purchaseId);
    }

    public function test_purchase_rejected_when_quotation_is_not_accepted(): void
    {
        $quotation = $this->makeQuotation(Quotation::STATUS_PENDING);

        Sanctum::actingAs($this->director);

        $response = $this->postJson('/api/purchases', [
            'quotation_id' => $quotation->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('quotation_id');

        $this->assertEquals(Quotation::STATUS_PENDING, $quotation->fresh()->status);
    }

    public function test_purchase_rejects_supplier_that_was_not_contacted_in_quotation(): void
    {
        $quotation = $this->makeQuotation(Quotation::STATUS_ACCEPTED);
        $stranger = Supplier::factory()->create();

        Sanctum::actingAs($this->director);

        $response = $this->postJson('/api/purchases', [
            'quotation_id' => $quotation->id,
            'supplier_id' => $stranger->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('supplier_id');
    }

    public function test_purchase_creation_still_requires_supplier_and_items_without_quotation(): void
    {
        Sanctum::actingAs($this->director);

        $response = $this->postJson('/api/purchases', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['supplier_id', 'items']);
    }

    public function test_items_can_be_overridden_when_creating_purchase_from_quotation(): void
    {
        $quotation = $this->makeQuotation(Quotation::STATUS_ACCEPTED);

        Sanctum::actingAs($this->director);

        $response = $this->postJson('/api/purchases', [
            'quotation_id' => $quotation->id,
            'items' => [
                ['name' => 'Cable THW 2.5mm', 'quantity' => 30, 'unit_price' => 900],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertCount(1, $response->json('data.items'));
        $response->assertJsonPath('data.items.0.name', 'Cable THW 2.5mm');
        $this->assertEquals(27000.0, $response->json('data.total'));
    }

    public function test_purchase_from_quotation_requires_items_and_suppliers(): void
    {
        // Cotización aceptada sin ítems: no hay nada que copiar
        $emptyQuotation = Quotation::factory()->accepted()->create(['created_by' => $this->director->id]);

        Sanctum::actingAs($this->director);

        $this->postJson('/api/purchases', ['quotation_id' => $emptyQuotation->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');

        // Cotización aceptada con ítems pero sin proveedores contactados
        $withoutSuppliers = Quotation::factory()->accepted()->create(['created_by' => $this->director->id]);
        $withoutSuppliers->items()->create([
            'product_id' => $this->product->id,
            'name' => $this->product->name,
            'quantity' => 3,
        ]);

        $this->postJson('/api/purchases', ['quotation_id' => $withoutSuppliers->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('supplier_id');
    }

    public function test_full_flow_from_quotation_to_completed_purchase(): void
    {
        Notification::fake();
        Storage::fake('public');

        Sanctum::actingAs($this->director);

        // 1. Cotización aceptada por un proveedor
        $quotation = $this->makeQuotation(Quotation::STATUS_PENDING);
        $this->patchJson('/api/quotations/' . $quotation->id . '/responses', [
            'supplier_id' => $this->supplier->id,
            'status' => QuotationSupplier::STATUS_ACCEPTED,
            'offer_total' => 540000,
        ])->assertStatus(201);

        $this->assertEquals(Quotation::STATUS_ACCEPTED, $quotation->fresh()->status);

        // 2. Orden de compra generada desde la cotización
        $purchaseResponse = $this->postJson('/api/purchases', [
            'quotation_id' => $quotation->id,
        ]);
        $purchaseResponse->assertStatus(201);

        $purchaseId = $purchaseResponse->json('data.id');
        $this->assertEquals(Quotation::STATUS_CONVERTED, $quotation->fresh()->status);

        // 3. Transición pendiente -> en_camino
        $this->patchJson('/api/purchases/' . $purchaseId . '/status', [
            'status' => Purchase::STATUS_IN_TRANSIT,
        ])->assertStatus(200);

        // 4. Llegada de la mercadería mediante escaneo de la guía
        $this->post('/api/purchases/' . $purchaseId . '/arrival-scan', [
            'document' => UploadedFile::fake()->create('guia-llegada.pdf', 40, 'application/pdf'),
            'guide_number' => 'GD-2026-777',
        ])->assertStatus(200);

        $this->assertEquals(Purchase::STATUS_COMPLETED, Purchase::find($purchaseId)->status);
        Notification::assertSentTo($this->warehouse, \App\Notifications\PurchaseArrivalNotification::class);
    }
}
