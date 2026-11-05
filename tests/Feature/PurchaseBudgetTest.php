<?php

namespace Tests\Feature;

use App\Models\AnnualBudget;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * REQ-22: compras con reserva de presupuesto.
 */
class PurchaseBudgetTest extends TestCase
{
    use DatabaseTransactions;

    private User $director;

    private AnnualBudget $budget;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->budget = AnnualBudget::factory()->create(['initial_amount' => 1000000, 'available_amount' => 1000000]);
        $this->director = User::factory()->create(['role' => User::ROLE_DIRECTOR, 'is_active' => true]);
    }

    private function payload(array $override = []): array
    {
        return $override + [
            'annual_budget_id' => $this->budget->id,
            'supplier_id' => Supplier::factory()->create()->id,
            'items' => [['name' => 'Cable', 'quantity' => 10, 'unit_price' => 1500], ['name' => 'Pinza', 'quantity' => 4, 'unit_price' => 8000]],
        ];
    }

    private function purchase(int $total = 47000): Purchase
    {
        Sanctum::actingAs($this->director);
        $payload = $this->payload(['items' => [['name' => 'Ítem', 'quantity' => 1, 'unit_price' => $total]]]);
        $id = $this->postJson('/api/purchases', $payload)->assertCreated()->json('data.id');

        return Purchase::findOrFail($id);
    }

    public function test_creating_a_purchase_reserves_its_total(): void
    {
        Sanctum::actingAs($this->director);

        $this->postJson('/api/purchases', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.total', 47000)
            ->assertJsonPath('data.reserved_amount', '47000.00')
            ->assertJsonPath('data.annual_budget_id', $this->budget->id);

        $budget = $this->budget->fresh();
        $this->assertSame('953000.00', $budget->available_amount);
        $this->assertSame('47000.00', $budget->reserved_amount);
        $this->assertDatabaseHas('budget_movements', ['annual_budget_id' => $budget->id, 'type' => 'reserva', 'amount' => '47000.00']);
    }

    public function test_insufficient_funds_missing_price_and_foreign_career_are_rejected(): void
    {
        Sanctum::actingAs($this->director);

        $this->postJson('/api/purchases', $this->payload(['items' => [['name' => 'Caro', 'quantity' => 1, 'unit_price' => 2000000]]]))
            ->assertStatus(422)->assertJsonValidationErrors('total');
        $this->assertSame('1000000.00', $this->budget->fresh()->available_amount);
        $this->assertDatabaseCount('budget_movements', 0);

        $this->postJson('/api/purchases', $this->payload(['items' => [['name' => 'Sin precio', 'quantity' => 1]]]))
            ->assertStatus(422)->assertJsonValidationErrors('items.0.unit_price');

        $this->postJson('/api/purchases', $this->payload(['annual_budget_id' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('annual_budget_id');

        // DIR-01 es director de área: puede usar el presupuesto de cualquier carrera.
        $other = AnnualBudget::factory()->create();
        $this->postJson('/api/purchases', $this->payload(['annual_budget_id' => $other->id]))->assertCreated();
    }

    public function test_roles_cannot_create_or_cancel(): void
    {
        $purchase = $this->purchase();

        foreach ([User::ROLE_WAREHOUSE, User::ROLE_TEACHER] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role, 'is_active' => true]));
            $this->postJson('/api/purchases', $this->payload())->assertForbidden();
            $this->postJson("/api/purchases/{$purchase->id}/cancel")->assertForbidden();
        }

        $otherDirector = User::factory()->create(['role' => User::ROLE_DIRECTOR, 'is_active' => true]);
        Sanctum::actingAs($otherDirector);
        $this->postJson("/api/purchases/{$purchase->id}/cancel")->assertOk();
    }

    public function test_cancel_releases_the_reservation_and_final_states_are_rejected(): void
    {
        $purchase = $this->purchase();
        Sanctum::actingAs($this->director);

        $this->postJson("/api/purchases/{$purchase->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelada')
            ->assertJsonPath('data.reserved_amount', '0.00');

        $budget = $this->budget->fresh();
        $this->assertSame('1000000.00', $budget->available_amount);
        $this->assertSame('0.00', $budget->reserved_amount);
        $this->assertDatabaseHas('budget_movements', ['purchase_id' => $purchase->id, 'type' => 'liberacion_reserva']);

        $this->postJson("/api/purchases/{$purchase->id}/cancel")->assertStatus(422);

        $completed = $this->purchase(1000);
        $completed->forceFill(['status' => Purchase::STATUS_COMPLETED])->save();
        $this->postJson("/api/purchases/{$completed->id}/cancel")->assertStatus(422);

        $this->patchJson("/api/purchases/{$purchase->id}/status", ['status' => 'cancelada'])
            ->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_cancel_from_in_transit_is_allowed(): void
    {
        $purchase = $this->purchase();
        Sanctum::actingAs($this->director);
        $this->patchJson("/api/purchases/{$purchase->id}/status", ['status' => 'en_camino'])->assertOk();

        $this->postJson("/api/purchases/{$purchase->id}/cancel")->assertOk();
        $this->assertSame('1000000.00', $this->budget->fresh()->available_amount);
    }
}
