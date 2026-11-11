<?php

namespace Tests\Feature;

use App\Models\AnnualBudget;
use App\Models\AuditLog;
use App\Models\BudgetMovement;
use App\Models\InvoiceIntake;
use App\Models\Product;
use App\Models\User;
use App\Services\InvoiceOcrService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesPurchases;
use Tests\Concerns\CreatesQuotations;
use Tests\TestCase;

/**
 * Exceso de presupuesto en factura (solo DIR-01), factura duplicada y trazabilidad de la OC.
 */
class InvoiceOverBudgetTraceTest extends TestCase
{
    use CreatesPurchases;
    use CreatesQuotations;
    use DatabaseTransactions;

    private AnnualBudget $budget;

    private Product $supply;

    protected function setUp(): void
    {
        parent::setUp();
        $this->budget = AnnualBudget::factory()->create(['initial_amount' => 1000, 'available_amount' => 1000]);
        $this->supply = Product::factory()->supply()->create(['quantity' => 0]);
    }

    private function as(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function confirm(InvoiceIntake $intake, array $extra = [], string $number = 'F-1', int $price = 1500)
    {
        return $this->postJson("/api/invoice-intakes/{$intake->id}/confirm", $extra + [
            'document_number' => $number,
            'items' => [['product_id' => $this->supply->id, 'quantity' => 1, 'unit_price' => $price]],
        ]);
    }

    private function draft(): InvoiceIntake
    {
        return InvoiceIntake::factory()->create(['annual_budget_id' => $this->budget->id]);
    }

    public function test_over_budget_is_an_alert_and_only_director_can_authorize_it(): void
    {
        $this->as(User::ROLE_DIRECTOR);
        $intake = $this->draft();

        $this->confirm($intake)->assertStatus(422)
            ->assertJsonPath('code', 'over_budget')->assertJsonPath('over_by', '500.00')
            ->assertJsonPath('free_amount', '1000.00')->assertJsonPath('total', '1500.00');

        $this->as(User::ROLE_ADMIN);
        $this->confirm($intake, ['allow_over_budget' => true])->assertForbidden()->assertJsonPath('code', 'over_budget');
        $this->assertSame('borrador', $intake->fresh()->status);

        $director = $this->as(User::ROLE_DIRECTOR);
        $this->confirm($intake, ['allow_over_budget' => true])->assertOk()->assertJsonPath('data.status', 'confirmado');

        $this->assertSame('-500.00', $this->budget->fresh()->available_amount);
        $this->assertDatabaseHas('budget_movements', ['invoice_intake_id' => $intake->id, 'type' => 'consumo', 'user_id' => $director->id]);
        $this->assertStringContainsString('500', BudgetMovement::where('invoice_intake_id', $intake->id)->where('type', 'consumo')->value('notes'));
        $log = AuditLog::where('action', 'over_budget_authorized')->firstOrFail();
        $this->assertSame($director->id, $log->new_values['authorized_by']);
        $this->assertSame('500.00', $log->new_values['over_by']);
    }

    public function test_allow_over_budget_without_excess_is_harmless_and_invalid_value_is_422(): void
    {
        $this->as(User::ROLE_ADMIN);
        $this->confirm($this->draft(), ['allow_over_budget' => true], 'F-2', 100)->assertOk();
        $this->confirm($this->draft(), ['allow_over_budget' => 'quizá'], 'F-3', 100)->assertStatus(422)->assertJsonValidationErrors('allow_over_budget');
    }

    public function test_reservation_blocks_other_spending_but_own_reservation_is_released_first(): void
    {
        $this->as(User::ROLE_DIRECTOR);
        $id = $this->reservedPurchase($this->budget, [['name' => 'Cable', 'quantity' => 1, 'unit_price' => 800]])->id;

        $this->assertThrows(
            fn () => $this->reservedPurchase($this->budget, [['name' => 'Otro', 'quantity' => 1, 'unit_price' => 300]]),
            ValidationException::class,
        );

        $this->getJson("/api/budgets/{$this->budget->id}")->assertOk()
            ->assertJsonPath('data.available_amount', '1000.00')->assertJsonPath('data.reserved_amount', '800.00')
            ->assertJsonPath('data.free_amount', '200.00');

        // Factura de la misma OC por 900: supera su reserva pero cabe en lo libre tras liberarla.
        $intake = InvoiceIntake::factory()->create(['annual_budget_id' => $this->budget->id, 'purchase_id' => $id]);
        $this->confirm($intake, [], 'F-4', 900)->assertOk()->assertJsonPath('data.linked', true);
        $this->assertSame('100.00', $this->budget->fresh()->available_amount);
    }

    public function test_duplicate_document_number_for_same_supplier_is_rejected(): void
    {
        $this->as(User::ROLE_DIRECTOR);
        $this->confirm($this->draft(), [], 'F-9', 100)->assertOk();

        $this->confirm($this->draft(), [], 'F-9', 100)->assertStatus(422)->assertJsonValidationErrors('document_number');
        $this->confirm($this->draft(), [], 'F-10', 100)->assertOk();
    }

    public function test_cancelled_invoice_number_can_be_reused(): void
    {
        $this->as(User::ROLE_DIRECTOR);
        $first = $this->draft();
        $this->confirm($first, [], 'F-9', 100)->assertOk();
        $this->postJson("/api/invoice-intakes/{$first->id}/cancel")->assertOk();

        $this->confirm($this->draft(), [], 'F-9', 100)->assertOk();
    }

    public function test_purchase_trace_chains_quotation_invoice_stock_and_movements(): void
    {
        $this->as(User::ROLE_DIRECTOR);
        // OC histórica ligada a una cotización del flujo antiguo (historial de solo lectura).
        $purchase = $this->reservedPurchase($this->budget, [['name' => 'Cable', 'quantity' => 2, 'unit_price' => 100]]);
        $purchase->forceFill(['quotation_id' => $quotationId = $this->acceptedQuotation()->id])->save();
        $id = $purchase->id;
        $intake = InvoiceIntake::factory()->create(['annual_budget_id' => $this->budget->id, 'purchase_id' => $id]);
        $this->confirm($intake, [], 'F-5', 100)->assertOk();

        $this->getJson("/api/purchases/$id/trace")->assertOk()
            ->assertJsonPath('purchase.id', $id)
            ->assertJsonPath('quotations.0.id', $quotationId)
            ->assertJsonPath('invoices.0.id', $intake->id)
            ->assertJsonPath('stock_entries.0.product_id', $this->supply->id)
            ->assertJsonPath('stock_entries.0.quantity', 1)
            ->assertJsonCount(3, 'budget_movements');

        foreach ([User::ROLE_WAREHOUSE, User::ROLE_ADMIN] as $role) {
            $this->as($role);
            $this->getJson("/api/purchases/$id/trace")->assertOk();
        }
        $this->as(User::ROLE_TEACHER);
        $this->getJson("/api/purchases/$id/trace")->assertForbidden();
        $this->getJson('/api/purchases/999999/trace')->assertNotFound();
    }

    public function test_ocr_reads_the_invoice_number_from_noisy_tesseract_headers(): void
    {
        $ocr = app(InvoiceOcrService::class);

        foreach (['FACTURA ELECTRONICA\nN* 1111' => '1111', "SANTIAGO Ne208\nTIPO" => '208', 'Systelec- a N? 20050' => '20050'] as $text => $number) {
            $this->assertSame($number, $ocr->parse($text)['invoice_number']);
        }
    }
}
