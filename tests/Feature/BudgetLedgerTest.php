<?php

namespace Tests\Feature;

use App\Models\AnnualBudget;
use App\Models\BudgetMovement;
use App\Models\Career;
use App\Models\InvoiceIntake;
use App\Models\Purchase;
use App\Models\User;
use App\Services\AnnualBudgetService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * REQ-21: ledger y reserva de presupuesto.
 */
class BudgetLedgerTest extends TestCase
{
    use DatabaseTransactions;

    private AnnualBudgetService $service;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AnnualBudgetService::class);
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
    }

    private function budget(int $initial = 1000000): AnnualBudget
    {
        return AnnualBudget::factory()->create(['initial_amount' => $initial, 'available_amount' => $initial]);
    }

    private function assertInvariant(AnnualBudget $budget, string $available, string $reserved): void
    {
        $fresh = $budget->fresh();
        $this->assertSame($available, $fresh->available_amount);
        $this->assertSame($reserved, $fresh->reserved_amount);
    }

    public function test_reserve_release_consume_and_restore_keep_the_invariant_and_leave_movements(): void
    {
        $budget = $this->budget();
        $purchase = Purchase::factory()->create(['annual_budget_id' => $budget->id]);
        $intake = InvoiceIntake::factory()->create(['annual_budget_id' => $budget->id]);

        $this->service->reserve($budget, 300000, $purchase, $this->admin->id);
        $this->assertInvariant($budget, '700000.00', '300000.00');
        $this->assertSame('300000.00', $purchase->fresh()->reserved_amount);

        $this->service->releaseReservation($budget, $purchase->fresh(), $this->admin->id);
        $this->assertInvariant($budget, '1000000.00', '0.00');
        $this->assertSame('0.00', $purchase->fresh()->reserved_amount);

        $this->service->consume($budget, 250000, $intake, $this->admin->id);
        $this->assertInvariant($budget, '750000.00', '0.00');
        $this->assertSame(250000 * 100, $this->service->consumedCents($budget->fresh()));

        $this->service->restore($budget, 250000, $intake, $this->admin->id);
        $this->assertInvariant($budget, '1000000.00', '0.00');

        $this->assertSame(
            ['reserva', 'liberacion_reserva', 'consumo', 'reposicion'],
            BudgetMovement::where('annual_budget_id', $budget->id)->orderBy('id')->pluck('type')->all()
        );
    }

    public function test_reserve_above_available_is_rejected_and_sequential_reserves_do_not_overdraw(): void
    {
        $budget = $this->budget(100000);
        $first = Purchase::factory()->create(['annual_budget_id' => $budget->id]);
        $second = Purchase::factory()->create(['annual_budget_id' => $budget->id]);

        $this->service->reserve($budget, 80000, $first, $this->admin->id);

        try {
            $this->service->reserve($budget, 30000, $second, $this->admin->id);
            $this->fail('La segunda reserva debía rechazarse.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('total', $e->errors());
        }

        $this->assertInvariant($budget, '20000.00', '80000.00');
        $this->assertSame(1, BudgetMovement::where('annual_budget_id', $budget->id)->count());
    }

    public function test_create_records_initial_assignment_and_patch_records_adjustment(): void
    {
        Sanctum::actingAs($this->admin);
        $career = Career::factory()->create();

        $id = $this->postJson('/api/budgets', ['career_id' => $career->id, 'year' => 2051, 'initial_amount' => 500000])
            ->assertCreated()->assertJsonPath('data.reserved_amount', '0.00')->json('data.id');

        $this->assertDatabaseHas('budget_movements', ['annual_budget_id' => $id, 'type' => 'asignacion_inicial', 'amount' => '500000.00']);

        $this->patchJson("/api/budgets/$id", ['initial_amount' => 650000])->assertOk();

        $adjustment = BudgetMovement::where('annual_budget_id', $id)->where('type', 'ajuste')->firstOrFail();
        $this->assertSame('150000.00', $adjustment->amount);
        $this->assertStringContainsString('500.000', $adjustment->notes);
        $this->assertStringContainsString('650.000', $adjustment->notes);
    }

    public function test_patch_below_consumed_plus_reserved_is_rejected(): void
    {
        Sanctum::actingAs($this->admin);
        $budget = $this->budget();
        $purchase = Purchase::factory()->create(['annual_budget_id' => $budget->id]);
        $this->service->reserve($budget, 300000, $purchase, $this->admin->id);
        $this->service->consume($budget->fresh(), 200000, InvoiceIntake::factory()->create(['annual_budget_id' => $budget->id]), $this->admin->id);

        $this->patchJson("/api/budgets/{$budget->id}", ['initial_amount' => 400000])
            ->assertStatus(422)->assertJsonValidationErrors('initial_amount');

        $this->patchJson("/api/budgets/{$budget->id}", ['initial_amount' => 600000])
            ->assertOk()
            ->assertJsonPath('data.available_amount', '100000.00')
            ->assertJsonPath('data.reserved_amount', '300000.00')
            ->assertJsonPath('data.consumed_amount', '200000.00');
    }
}
