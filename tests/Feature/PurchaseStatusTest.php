<?php

namespace Tests\Feature;

use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Notifications\PurchaseArrivalNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PurchaseStatusTest extends TestCase
{
    use DatabaseTransactions;

    protected User $director;

    protected User $warehouse;

    protected User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->director = User::factory()->create([
            'email' => 'dir_compra_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_DIRECTOR,
            'is_active' => true,
        ]);

        $this->warehouse = User::factory()->create([
            'email' => 'panol_compra_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_WAREHOUSE,
            'is_active' => true,
        ]);

        $this->teacher = User::factory()->create([
            'email' => 'docente_compra_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_TEACHER,
            'is_active' => true,
        ]);
    }

    protected function makePurchase(array $attributes = []): Purchase
    {
        return Purchase::factory()->create(array_merge([
            'created_by' => $this->director->id,
        ], $attributes));
    }

    public function test_director_can_create_purchase_order_with_items(): void
    {
        Sanctum::actingAs($this->director);

        $supplier = Supplier::factory()->create();

        $response = $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id,
            'notes' => 'Compra de materiales para taller',
            'items' => [
                ['name' => 'Cable THW 1.5mm', 'quantity' => 10, 'unit_price' => 1500],
                ['name' => 'Pinza de presión', 'quantity' => 4, 'unit_price' => 8000],
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', Purchase::STATUS_PENDING);
        $response->assertJsonPath('data.supplier.id', $supplier->id);

        $this->assertStringStartsWith('OC-', $response->json('data.code'));
        $this->assertEquals(10 * 1500 + 4 * 8000, $response->json('data.total'));
        $this->assertCount(2, $response->json('data.items'));

        $this->assertDatabaseHas('purchases', [
            'id' => $response->json('data.id'),
            'status' => Purchase::STATUS_PENDING,
            'created_by' => $this->director->id,
        ]);
        $this->assertDatabaseCount('purchase_items', 2);
    }

    public function test_create_purchase_requires_at_least_one_item(): void
    {
        Sanctum::actingAs($this->director);

        $supplier = Supplier::factory()->create();

        $response = $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id,
            'items' => [],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('items');
    }

    public function test_warehouse_cannot_create_purchase_order(): void
    {
        Sanctum::actingAs($this->warehouse);

        $response = $this->postJson('/api/purchases', [
            'supplier_id' => Supplier::factory()->create()->id,
            'items' => [['name' => 'Multímetro', 'quantity' => 2]],
        ]);

        $response->assertStatus(403);
    }

    public function test_teacher_cannot_list_purchases(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->getJson('/api/purchases')->assertStatus(403);
    }

    public function test_can_list_purchases_filtered_by_status(): void
    {
        Sanctum::actingAs($this->director);

        $pending = $this->makePurchase(['status' => Purchase::STATUS_PENDING]);
        $inTransit = $this->makePurchase(['status' => Purchase::STATUS_IN_TRANSIT]);
        $this->makePurchase(['status' => Purchase::STATUS_COMPLETED]);

        $response = $this->getJson('/api/purchases?status=en_camino');

        $response->assertStatus(200);
        $response->assertJsonFragment(['id' => $inTransit->id]);
        $response->assertJsonMissing(['id' => $pending->id]);
        $this->assertCount(1, $response->json('data'));

        // Alias en español
        $this->getJson('/api/purchases?estado=pendiente')
            ->assertStatus(200)
            ->assertJsonFragment(['id' => $pending->id]);

        // Sin filtro retorna todas
        $this->getJson('/api/purchases')->assertStatus(200)->assertJsonCount(3, 'data');
    }

    public function test_list_purchases_rejects_unknown_status_filter(): void
    {
        Sanctum::actingAs($this->director);

        $response = $this->getJson('/api/purchases?status=no_existe');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('status');
    }

    public function test_can_get_purchase_detail_with_items(): void
    {
        Sanctum::actingAs($this->warehouse);

        $purchase = $this->makePurchase();
        $purchase->items()->create([
            'name' => 'Tornillería variada',
            'quantity' => 20,
            'unit_price' => 250,
        ]);

        $response = $this->getJson('/api/purchases/' . $purchase->id);

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $purchase->id);
        $response->assertJsonPath('data.status', Purchase::STATUS_PENDING);
        $response->assertJsonPath('data.items.0.name', 'Tornillería variada');
        $response->assertJsonPath('data.allowed_transitions', [Purchase::STATUS_IN_TRANSIT]);
    }

    public function test_valid_transition_from_pending_to_in_transit(): void
    {
        Sanctum::actingAs($this->director);

        $purchase = $this->makePurchase(['status' => Purchase::STATUS_PENDING]);

        $response = $this->patchJson('/api/purchases/' . $purchase->id . '/status', [
            'status' => Purchase::STATUS_IN_TRANSIT,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('purchase.status', Purchase::STATUS_IN_TRANSIT);

        $this->assertEquals(Purchase::STATUS_IN_TRANSIT, $purchase->fresh()->status);
    }

    public function test_transition_from_pending_to_completed_is_rejected(): void
    {
        Sanctum::actingAs($this->director);

        $purchase = $this->makePurchase(['status' => Purchase::STATUS_PENDING]);

        $response = $this->patchJson('/api/purchases/' . $purchase->id . '/status', [
            'status' => Purchase::STATUS_COMPLETED,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('current_status', Purchase::STATUS_PENDING);
        $response->assertJsonPath('allowed_transitions', [Purchase::STATUS_IN_TRANSIT]);

        $this->assertEquals(Purchase::STATUS_PENDING, $purchase->fresh()->status);
    }

    public function test_backward_transition_is_rejected(): void
    {
        Sanctum::actingAs($this->warehouse);

        $purchase = $this->makePurchase(['status' => Purchase::STATUS_COMPLETED]);

        $response = $this->patchJson('/api/purchases/' . $purchase->id . '/status', [
            'estado' => Purchase::STATUS_PENDING,
        ]);

        $response->assertStatus(422);
        $this->assertEquals(Purchase::STATUS_COMPLETED, $purchase->fresh()->status);
    }

    public function test_invalid_status_value_is_rejected_by_validation(): void
    {
        Sanctum::actingAs($this->director);

        $purchase = $this->makePurchase();

        $response = $this->patchJson('/api/purchases/' . $purchase->id . '/status', [
            'status' => 'perdida',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('status');
    }

    public function test_arrival_scan_completes_purchase_and_notifies_warehouse(): void
    {
        Notification::fake();
        Storage::fake('public');

        Sanctum::actingAs($this->warehouse);

        $purchase = $this->makePurchase(['status' => Purchase::STATUS_IN_TRANSIT]);

        $response = $this->post('/api/purchases/' . $purchase->id . '/arrival-scan', [
            'document' => UploadedFile::fake()->create('guia-despacho.pdf', 50, 'application/pdf'),
            'guide_number' => 'GD-778899',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('purchase.status', Purchase::STATUS_COMPLETED);
        $response->assertJsonPath('guide_number', 'GD-778899');

        $purchase->refresh();
        $this->assertEquals(Purchase::STATUS_COMPLETED, $purchase->status);
        $this->assertNotNull($purchase->received_at);
        $this->assertNotNull($purchase->arrival_document);
        $this->assertNotNull($purchase->invoice_number);

        Notification::assertSentTo($this->warehouse, PurchaseArrivalNotification::class);
    }

    public function test_arrival_scan_can_be_done_with_guide_number_only(): void
    {
        Notification::fake();

        Sanctum::actingAs($this->director);

        $purchase = $this->makePurchase(['status' => Purchase::STATUS_PENDING]);

        $response = $this->postJson('/api/purchases/' . $purchase->id . '/arrival-scan', [
            'guide_number' => 'GD-112233',
            'invoice_number' => 'FAC-445566',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('purchase.status', Purchase::STATUS_COMPLETED);
        $response->assertJsonPath('invoice_number', 'FAC-445566');

        Notification::assertSentTo($this->warehouse, PurchaseArrivalNotification::class);
    }

    public function test_arrival_scan_rejects_already_completed_purchase(): void
    {
        Notification::fake();

        Sanctum::actingAs($this->director);

        $purchase = $this->makePurchase(['status' => Purchase::STATUS_COMPLETED]);

        $response = $this->postJson('/api/purchases/' . $purchase->id . '/arrival-scan', [
            'guide_number' => 'GD-999999',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('current_status', Purchase::STATUS_COMPLETED);

        Notification::assertNotSentTo($this->warehouse, PurchaseArrivalNotification::class);
    }

    public function test_arrival_scan_requires_document_or_guide_number(): void
    {
        Sanctum::actingAs($this->director);

        $purchase = $this->makePurchase(['status' => Purchase::STATUS_IN_TRANSIT]);

        $response = $this->postJson('/api/purchases/' . $purchase->id . '/arrival-scan', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('document');

        $this->assertEquals(Purchase::STATUS_IN_TRANSIT, $purchase->fresh()->status);
    }

    public function test_teacher_cannot_scan_arrival(): void
    {
        Sanctum::actingAs($this->teacher);

        $purchase = $this->makePurchase(['status' => Purchase::STATUS_IN_TRANSIT]);

        $this->postJson('/api/purchases/' . $purchase->id . '/arrival-scan', [
            'guide_number' => 'GD-123123',
        ])->assertStatus(403);
    }
}
