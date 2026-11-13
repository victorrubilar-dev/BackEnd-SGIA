<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Notifications\PurchaseRequestStatusNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsPurchaseRequests;
use Tests\TestCase;

/**
 * Solicitud de compra: crear, editar en borrador, aprobar y cancelar (Fase 2).
 */
class PurchaseRequestTest extends TestCase
{
    use BuildsPurchaseRequests, DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPurchaseFlow();
    }

    public function test_director_creates_request_with_yearly_code(): void
    {
        $product = Product::factory()->supply()->create();
        Sanctum::actingAs($this->director);

        $first = $this->postJson('/api/purchase-requests', [
            'annual_budget_id' => $this->budget->id,
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
            'notes' => 'Para laboratorio',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'borrador')
            ->assertJsonPath('data.items.0.product_id', $product->id)
            ->assertJsonPath('data.items.0.quantity', 4)
            ->assertJsonPath('data.items.0.product.name', $product->name)
            ->assertJsonPath('data.can_approve', true)
            ->assertJsonPath('data.created_by', $this->director->id)
            ->json('data.code');

        $this->assertMatchesRegularExpression('/^SC-'.now()->year.'-\d{4}$/', $first);

        $second = $this->createRequest()->code;
        $this->assertSame((int) substr($first, -4) + 1, (int) substr($second, -4));
    }

    public function test_create_validates_items_and_budget(): void
    {
        $product = Product::factory()->supply()->create();
        Sanctum::actingAs($this->director);

        $this->postJson('/api/purchase-requests', ['items' => []])
            ->assertStatus(422)->assertJsonValidationErrors(['annual_budget_id', 'items']);

        $this->postJson('/api/purchase-requests', [
            'annual_budget_id' => $this->budget->id,
            'items' => [['product_id' => $product->id, 'quantity' => 0], ['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors(['items.0.quantity', 'items.0.product_id']);
    }

    public function test_only_director_writes_and_readers_can_list(): void
    {
        $request = $this->createRequest();

        foreach ([User::ROLE_ADMIN, User::ROLE_WAREHOUSE, User::ROLE_TEACHER] as $role) {
            Sanctum::actingAs($this->userWithRole($role));
            $this->postJson('/api/purchase-requests', ['annual_budget_id' => $this->budget->id, 'items' => []])->assertForbidden();
            $this->postJson("/api/purchase-requests/{$request->id}/approve")->assertForbidden();
            $this->postJson("/api/purchase-requests/{$request->id}/cancel")->assertForbidden();
        }

        foreach ([User::ROLE_ADMIN, User::ROLE_WAREHOUSE] as $role) {
            Sanctum::actingAs($this->userWithRole($role));
            $this->getJson('/api/purchase-requests')->assertOk();
            // El presupuesto trae su carrera (la interfaz lo rotula "ISD · …" y no "Carrera #1").
            $this->getJson("/api/purchase-requests/{$request->id}")->assertOk()->assertJsonPath('data.code', $request->code)
                ->assertJsonPath('data.annual_budget.career.id', $request->annualBudget->career_id);
        }

        Sanctum::actingAs($this->userWithRole(User::ROLE_TEACHER));
        $this->getJson('/api/purchase-requests')->assertForbidden();
    }

    public function test_index_filters(): void
    {
        $draft = $this->createRequest();
        $approved = $this->approvedRequest();
        Sanctum::actingAs($this->director);

        $ids = collect($this->getJson('/api/purchase-requests?status=aprobada&per_page=100')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($approved->id));
        $this->assertFalse($ids->contains($draft->id));

        $this->getJson('/api/purchase-requests?code='.$draft->code)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $draft->id);
        $this->getJson('/api/purchase-requests?from='.now()->addDay()->toDateString())->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/purchase-requests?status=otro')->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_edit_only_in_draft(): void
    {
        $request = $this->createRequest();
        $product = Product::factory()->supply()->create();

        $this->patchJson("/api/purchase-requests/{$request->id}", [
            'items' => [['product_id' => $product->id, 'quantity' => 9]],
            'notes' => 'Cambio',
        ])->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.quantity', 9)->assertJsonPath('data.notes', 'Cambio');

        $this->postJson("/api/purchase-requests/{$request->id}/approve")->assertOk();

        $this->patchJson("/api/purchase-requests/{$request->id}", ['notes' => 'Tarde'])
            ->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_approve_notifies_admins_and_only_from_draft(): void
    {
        Notification::fake();
        $admin = $this->userWithRole(User::ROLE_ADMIN);
        $request = $this->createRequest();

        $this->postJson("/api/purchase-requests/{$request->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'aprobada')
            ->assertJsonPath('data.approved_by', $this->director->id)
            ->assertJsonPath('data.can_send', true);

        Notification::assertSentTo($admin, PurchaseRequestStatusNotification::class);

        $this->postJson("/api/purchase-requests/{$request->id}/approve")
            ->assertStatus(422)->assertJsonValidationErrors('status');

        $this->assertDatabaseHas('audit_logs', ['auditable_type' => 'purchase_request', 'auditable_id' => $request->id, 'action' => 'purchase_request.status_changed']);
    }

    public function test_cancel_from_draft_and_not_twice(): void
    {
        $request = $this->createRequest();

        $this->postJson("/api/purchase-requests/{$request->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelada')->assertJsonPath('data.can_cancel', false);
        $this->postJson("/api/purchase-requests/{$request->id}/cancel")->assertStatus(422)->assertJsonValidationErrors('status');
        $this->assertSame(PurchaseRequest::STATUS_CANCELLED, $request->refresh()->status);
    }

    public function test_areas_catalog(): void
    {
        Sanctum::actingAs($this->userWithRole(User::ROLE_WAREHOUSE));

        $this->getJson('/api/purchase-requests/areas')->assertOk()
            ->assertJsonPath('data.0', ['value' => 'informatica', 'label' => 'Informática'])
            ->assertJsonCount(4, 'data');
    }
}
