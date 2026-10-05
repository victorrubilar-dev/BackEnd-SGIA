<?php

namespace Tests\Feature;

use App\Mail\QuotationRequestMail;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationSupplier;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QuotationTest extends TestCase
{
    use DatabaseTransactions;

    protected User $director;

    protected User $teacher;

    /** @var array<int, Supplier> */
    protected array $suppliers;

    /** @var array<int, Product> */
    protected array $products;

    protected function setUp(): void
    {
        parent::setUp();

        $this->director = User::factory()->create([
            'email' => 'dir_cot_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_DIRECTOR,
            'is_active' => true,
        ]);

        $this->teacher = User::factory()->create([
            'email' => 'docente_cot_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_TEACHER,
            'is_active' => true,
        ]);

        $this->suppliers = Supplier::factory()->count(3)->create()->all();
        $this->products = Product::factory()->count(2)->create()->all();
    }

    /**
     * @return array<int, int>
     */
    protected function supplierIds(): array
    {
        return array_map(fn (Supplier $supplier) => $supplier->id, $this->suppliers);
    }

    protected function validPayload(): array
    {
        return [
            'products' => [
                ['id' => $this->products[0]->id, 'quantity' => 5],
                ['id' => $this->products[1]->id, 'quantity' => 2],
            ],
            'supplier_ids' => $this->supplierIds(),
            'notes' => 'Cotización para el taller de electrónica',
        ];
    }

    public function test_director_can_create_quotation_and_emails_three_suppliers(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->director);

        $response = $this->postJson('/api/quotations', $this->validPayload());

        $response->assertStatus(201);
        $response->assertJsonPath('quotation.status', Quotation::STATUS_PENDING);
        $response->assertJsonPath('emails_sent', 3);
        $this->assertCount(2, $response->json('quotation.items'));
        $this->assertCount(3, $response->json('quotation.suppliers'));
        $this->assertStringStartsWith('COT-', $response->json('quotation.code'));

        $quotationId = $response->json('quotation.id');
        $this->assertDatabaseHas('quotations', [
            'id' => $quotationId,
            'status' => Quotation::STATUS_PENDING,
            'created_by' => $this->director->id,
        ]);
        $this->assertDatabaseCount('quotation_suppliers', 3);

        Mail::assertSent(QuotationRequestMail::class, 3);

        foreach ($this->suppliers as $supplier) {
            Mail::assertSent(
                QuotationRequestMail::class,
                fn (QuotationRequestMail $mail) => $mail->hasTo($supplier->email)
            );
        }
    }

    public function test_quotation_requires_at_least_three_suppliers(): void
    {
        Sanctum::actingAs($this->director);

        $payload = $this->validPayload();
        $payload['supplier_ids'] = [$this->suppliers[0]->id, $this->suppliers[1]->id];

        $response = $this->postJson('/api/quotations', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('supplier_ids');
    }

    public function test_quotation_requires_at_least_one_product(): void
    {
        Sanctum::actingAs($this->director);

        $payload = $this->validPayload();
        $payload['products'] = [];

        $response = $this->postJson('/api/quotations', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('products');
    }

    public function test_quotation_requires_positive_quantity(): void
    {
        Sanctum::actingAs($this->director);

        $payload = $this->validPayload();
        $payload['products'][0]['quantity'] = 0;

        $response = $this->postJson('/api/quotations', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('products.0.quantity');
    }

    public function test_teacher_cannot_create_quotation(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/quotations', $this->validPayload())->assertStatus(403);

        Mail::assertNothingSent();
    }

    public function test_can_list_quotations_filtered_by_status(): void
    {
        $pending = Quotation::factory()->create(['created_by' => $this->director->id]);
        $accepted = Quotation::factory()->accepted()->create(['created_by' => $this->director->id]);

        Sanctum::actingAs($this->director);

        $this->getJson('/api/quotations?status=aceptada')
            ->assertStatus(200)
            ->assertJsonFragment(['id' => $accepted->id])
            ->assertJsonMissing(['id' => $pending->id]);

        $this->getJson('/api/quotations?estado=pendiente')
            ->assertStatus(200)
            ->assertJsonFragment(['id' => $pending->id]);

        $this->getJson('/api/quotations?status=no_existe')->assertStatus(422);
    }

    public function test_quotation_detail_includes_items_and_contacted_suppliers(): void
    {
        $quotation = Quotation::factory()->create(['created_by' => $this->director->id]);
        $quotation->items()->create([
            'product_id' => $this->products[0]->id,
            'name' => $this->products[0]->name,
            'quantity' => 4,
        ]);
        $quotation->suppliers()->create([
            'supplier_id' => $this->suppliers[0]->id,
            'status' => QuotationSupplier::STATUS_ACCEPTED,
            'offer_total' => 250000,
            'responded_at' => now(),
        ]);
        $quotation->suppliers()->create(['supplier_id' => $this->suppliers[1]->id]);

        Sanctum::actingAs($this->director);

        $response = $this->getJson('/api/quotations/' . $quotation->id);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', Quotation::STATUS_PENDING);
        $this->assertCount(1, $response->json('data.items'));
        $this->assertCount(2, $response->json('data.suppliers'));
        $response->assertJsonPath('data.suppliers.0.status', QuotationSupplier::STATUS_ACCEPTED);
        $this->assertEquals(250000, $response->json('data.suppliers.0.offer_total'));
        $response->assertJsonPath('data.allowed_transitions', [Quotation::STATUS_ACCEPTED, Quotation::STATUS_REJECTED]);
    }

    public function test_quotation_status_transitions_follow_state_machine(): void
    {
        $quotation = Quotation::factory()->create(['created_by' => $this->director->id]);

        Sanctum::actingAs($this->director);

        // pendiente -> convertida no está permitido
        $this->patchJson('/api/quotations/' . $quotation->id . '/status', ['status' => Quotation::STATUS_CONVERTED])
            ->assertStatus(422);

        $this->assertEquals(Quotation::STATUS_PENDING, $quotation->fresh()->status);

        // pendiente -> aceptada
        $this->patchJson('/api/quotations/' . $quotation->id . '/status', ['estado' => Quotation::STATUS_ACCEPTED])
            ->assertStatus(200)
            ->assertJsonPath('quotation.status', Quotation::STATUS_ACCEPTED);

        // aceptada -> convertida
        $this->patchJson('/api/quotations/' . $quotation->id . '/status', ['status' => Quotation::STATUS_CONVERTED])
            ->assertStatus(200)
            ->assertJsonPath('quotation.status', Quotation::STATUS_CONVERTED);

        // convertida es estado final
        $this->patchJson('/api/quotations/' . $quotation->id . '/status', ['status' => Quotation::STATUS_PENDING])
            ->assertStatus(422);
    }

    public function test_recording_an_acceptance_moves_quotation_to_accepted(): void
    {
        $quotation = Quotation::factory()->create(['created_by' => $this->director->id]);

        foreach ($this->suppliers as $supplier) {
            $quotation->suppliers()->create(['supplier_id' => $supplier->id]);
        }

        Sanctum::actingAs($this->director);

        $response = $this->patchJson('/api/quotations/' . $quotation->id . '/responses', [
            'supplier_id' => $this->suppliers[0]->id,
            'status' => QuotationSupplier::STATUS_ACCEPTED,
            'offer_total' => 480000,
            'notes' => 'Entrega en 10 días hábiles',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('response.status', QuotationSupplier::STATUS_ACCEPTED);
        $response->assertJsonPath('quotation.status', Quotation::STATUS_ACCEPTED);

        $this->assertDatabaseHas('quotation_suppliers', [
            'quotation_id' => $quotation->id,
            'supplier_id' => $this->suppliers[0]->id,
            'status' => QuotationSupplier::STATUS_ACCEPTED,
            'offer_total' => 480000,
        ]);
    }

    public function test_quotation_is_rejected_when_all_suppliers_reject(): void
    {
        $quotation = Quotation::factory()->create(['created_by' => $this->director->id]);

        foreach ($this->suppliers as $supplier) {
            $quotation->suppliers()->create(['supplier_id' => $supplier->id]);
        }

        Sanctum::actingAs($this->director);

        foreach ($this->suppliers as $index => $supplier) {
            $this->patchJson('/api/quotations/' . $quotation->id . '/responses', [
                'supplier_id' => $supplier->id,
                'status' => QuotationSupplier::STATUS_REJECTED,
            ])->assertStatus(201);
        }

        $this->assertEquals(Quotation::STATUS_REJECTED, $quotation->fresh()->status);
    }

    public function test_cannot_record_response_from_supplier_that_was_not_contacted(): void
    {
        $quotation = Quotation::factory()->create(['created_by' => $this->director->id]);
        $quotation->suppliers()->create(['supplier_id' => $this->suppliers[0]->id]);

        Sanctum::actingAs($this->director);

        $response = $this->patchJson('/api/quotations/' . $quotation->id . '/responses', [
            'supplier_id' => $this->suppliers[1]->id,
            'status' => QuotationSupplier::STATUS_ACCEPTED,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('supplier_id');
    }
}
