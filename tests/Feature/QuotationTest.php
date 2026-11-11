<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationSupplier;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cotizaciones del flujo antiguo (REQ-07): solo lectura del historial. Las escrituras se retiraron en la
 * Fase 7 del nuevo flujo de compras (las cotizaciones se suben en la solicitud de compra).
 */
class QuotationTest extends TestCase
{
    use DatabaseTransactions;

    protected User $director;

    protected function setUp(): void
    {
        parent::setUp();

        $this->director = User::factory()->create(['role' => User::ROLE_DIRECTOR, 'is_active' => true]);
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

    public function test_quotation_detail_includes_items_and_contacted_suppliers_without_transitions(): void
    {
        $product = Product::factory()->create();
        [$a, $b] = Supplier::factory()->count(2)->create()->all();
        $quotation = Quotation::factory()->create(['created_by' => $this->director->id]);
        $quotation->items()->create(['product_id' => $product->id, 'name' => $product->name, 'quantity' => 4]);
        $quotation->suppliers()->create([
            'supplier_id' => $a->id,
            'status' => QuotationSupplier::STATUS_ACCEPTED,
            'offer_total' => 250000,
            'responded_at' => now(),
        ]);
        $quotation->suppliers()->create(['supplier_id' => $b->id]);

        Sanctum::actingAs($this->director);

        $response = $this->getJson('/api/quotations/'.$quotation->id);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', Quotation::STATUS_PENDING);
        $this->assertCount(1, $response->json('data.items'));
        $this->assertCount(2, $response->json('data.suppliers'));
        $response->assertJsonPath('data.suppliers.0.status', QuotationSupplier::STATUS_ACCEPTED);
        $this->assertEquals(250000, $response->json('data.suppliers.0.offer_total'));
        // Historial de solo lectura: sin transiciones disponibles.
        $response->assertJsonPath('data.allowed_transitions', []);
    }

    public function test_history_roles(): void
    {
        $quotation = Quotation::factory()->create(['created_by' => $this->director->id]);

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]));
        $this->getJson("/api/quotations/{$quotation->id}")->assertOk();

        foreach ([User::ROLE_WAREHOUSE, User::ROLE_TEACHER] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role, 'is_active' => true]));
            $this->getJson('/api/quotations')->assertForbidden();
        }
    }

    public function test_write_endpoints_were_retired(): void
    {
        $quotation = Quotation::factory()->create(['created_by' => $this->director->id]);
        Sanctum::actingAs($this->director);

        $this->postJson('/api/quotations', ['products' => []])->assertStatus(405);
        $this->patchJson("/api/quotations/{$quotation->id}/status", ['status' => 'aceptada'])->assertNotFound();
        $this->patchJson("/api/quotations/{$quotation->id}/responses", [])->assertNotFound();
        $this->postJson("/api/quotations/{$quotation->id}/resend")->assertNotFound();
        $this->postJson('/api/purchases', ['quotation_id' => $quotation->id])->assertStatus(405);

        $this->assertSame(Quotation::STATUS_PENDING, $quotation->refresh()->status);
    }
}
