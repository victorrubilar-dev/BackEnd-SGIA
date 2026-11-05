<?php

namespace Tests\Feature;

use App\Models\AnnualBudget;
use App\Models\BudgetMovement;
use App\Models\InvoiceIntake;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesQuotations;
use Tests\TestCase;

/**
 * REQ-22: compras con reserva de presupuesto.
 */
class PurchaseBudgetTest extends TestCase
{
    use CreatesQuotations;
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
            'quotation_id' => $this->acceptedQuotation()->id,
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

    public function test_confirming_a_linked_invoice_releases_the_reservation_and_consumes_the_real_amount(): void
    {
        Process::fake(['*' => Process::result('')]);
        $purchase = $this->purchase(100000);
        $supply = Product::factory()->supply()->create(['quantity' => 0, 'stock_minimo' => 1]);

        $intakeId = $this->postJson('/api/invoice-intakes', [
            'file' => UploadedFile::fake()->create('f.pdf', 10, 'application/pdf'),
            'annual_budget_id' => $this->budget->id,
            'purchase_id' => $purchase->id,
        ])->assertCreated()->assertJsonPath('data.purchase_id', $purchase->id)->json('data.id');

        $this->assertSame('900000.00', $this->budget->fresh()->available_amount);

        // La factura real es menor que lo reservado: la diferencia vuelve al disponible.
        $this->postJson("/api/invoice-intakes/$intakeId/confirm", [
            'document_number' => 'F-9',
            'items' => [['product_id' => $supply->id, 'quantity' => 10, 'unit_price' => 9000]],
        ])->assertOk()->assertJsonPath('data.total_deducted', '90000.00');

        $budget = $this->budget->fresh();
        $this->assertSame('910000.00', $budget->available_amount);
        $this->assertSame('0.00', $budget->reserved_amount);
        $this->assertSame('0.00', $purchase->fresh()->reserved_amount);
        $this->assertSame(
            ['reserva', 'liberacion_reserva', 'consumo'],
            BudgetMovement::where('annual_budget_id', $budget->id)->orderBy('id')->pluck('type')->all()
        );

        // La compra ya facturada no admite otra factura ni cancelación con reposición.
        $this->postJson('/api/invoice-intakes', [
            'file' => UploadedFile::fake()->create('g.pdf', 10, 'application/pdf'),
            'annual_budget_id' => $this->budget->id,
            'purchase_id' => $purchase->id,
        ])->assertStatus(422)->assertJsonValidationErrors('purchase_id');
    }

    public function test_linked_invoice_above_budget_plus_released_reservation_is_rejected(): void
    {
        Process::fake(['*' => Process::result('')]);
        $purchase = $this->purchase(100000);
        $supply = Product::factory()->supply()->create(['quantity' => 0]);
        $intake = InvoiceIntake::factory()->create([
            'annual_budget_id' => $this->budget->id,
            'purchase_id' => $purchase->id,
        ]);

        $this->postJson("/api/invoice-intakes/{$intake->id}/confirm", [
            'document_number' => 'F-10',
            'items' => [['product_id' => $supply->id, 'quantity' => 1, 'unit_price' => 1100000]],
        ])->assertStatus(422)->assertJsonValidationErrors('items');

        $this->assertSame('900000.00', $this->budget->fresh()->available_amount);
        $this->assertSame('100000.00', $purchase->fresh()->reserved_amount);
        $this->assertSame('borrador', $intake->fresh()->status);
    }

    public function test_invoice_linked_to_purchase_of_another_budget_is_rejected(): void
    {
        $purchase = $this->purchase();
        $other = AnnualBudget::factory()->create();

        $this->postJson('/api/invoice-intakes', [
            'file' => UploadedFile::fake()->create('f.pdf', 10, 'application/pdf'),
            'annual_budget_id' => $other->id,
            'purchase_id' => $purchase->id,
        ])->assertStatus(422)->assertJsonValidationErrors('purchase_id');
    }
}
