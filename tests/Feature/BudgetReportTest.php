<?php

namespace Tests\Feature;

use App\Models\AnnualBudget;
use App\Models\Cajon;
use App\Models\Career;
use App\Models\InvoiceIntake;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsProductPayload;
use Tests\Concerns\CreatesPurchases;
use Tests\TestCase;

/**
 * REQ-24: reportes de ejecución presupuestaria.
 */
class BudgetReportTest extends TestCase
{
    use BuildsProductPayload;
    use CreatesPurchases;
    use DatabaseTransactions;

    private User $admin;

    private AnnualBudget $budget;

    private Supplier $supplier;

    private Product $supply;

    private Product $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $this->budget = AnnualBudget::factory()->create(['year' => (int) now()->format('Y'), 'initial_amount' => 1000000, 'available_amount' => 1000000]);
        $this->supplier = Supplier::factory()->create(['name' => 'Proveedor Uno']);
        $this->supply = Product::factory()->supply()->create(['name' => 'Cable', 'quantity' => 0]);
        $this->other = Product::factory()->scaleModel()->create(array_merge($this->unitPayload(), ['name' => 'Maqueta', 'quantity' => 0]));
        Sanctum::actingAs($this->admin);
    }

    /** Compra de 200.000 reservada y facturada por 150.000 (cable x10 a 5.000 + maqueta x1 a 100.000). */
    private function seedActivity(): InvoiceIntake
    {
        $purchaseId = $this->reservedPurchase($this->budget, [['name' => 'Cable', 'quantity' => 1, 'unit_price' => 200000]], $this->supplier)->id;

        $intake = InvoiceIntake::factory()->create(['annual_budget_id' => $this->budget->id, 'purchase_id' => $purchaseId]);
        $this->postJson("/api/invoice-intakes/{$intake->id}/confirm", [
            'document_number' => 'F-1',
            'items' => [
                ['product_id' => $this->supply->id, 'quantity' => 10, 'unit_price' => 5000],
                ['product_id' => $this->other->id, 'quantity' => 1, 'unit_price' => 100000, 'cajon_id' => Cajon::factory()->create()->id],
            ],
        ])->assertOk();

        return $intake;
    }

    public function test_summary_reports_execution_per_career_and_year(): void
    {
        $this->seedActivity();
        $reserve = $this->reservedPurchase($this->budget, [['name' => 'Otro', 'quantity' => 1, 'unit_price' => 250000]], $this->supplier);

        $row = collect($this->getJson("/api/budgets/summary?year={$this->budget->year}&career_id={$this->budget->career_id}")
            ->assertOk()->json('data'))->firstWhere('id', $this->budget->id);

        $this->assertSame('1000000.00', $row['initial_amount']);
        $this->assertSame('250000.00', $row['reserved_amount']);
        $this->assertSame('150000.00', $row['consumed_amount']);
        $this->assertSame('850000.00', $row['available_amount']);
        $this->assertSame('600000.00', $row['free_amount']);
        $this->assertEquals(40.0, $row['execution_percent']);
        $this->assertNotNull($reserve->id);
    }

    public function test_summary_is_zero_percent_without_initial_amount_data_and_empty_year_returns_nothing(): void
    {
        $this->getJson('/api/budgets/summary?year=2001')->assertOk()->assertJsonCount(0, 'data');
        $row = collect($this->getJson("/api/budgets/summary?career_id={$this->budget->career_id}")->json('data'))->firstWhere('id', $this->budget->id);
        $this->assertEquals(0, $row['execution_percent']);
    }

    public function test_movements_are_paginated_and_filterable(): void
    {
        $intake = $this->seedActivity();

        $all = $this->getJson("/api/budgets/{$this->budget->id}/movements")->assertOk();
        $this->assertSame(['consumo', 'liberacion_reserva', 'reserva'], array_column($all->json('data'), 'type'));
        $this->assertSame($this->admin->id, $all->json('data.0.user.id'));

        $this->getJson("/api/budgets/{$this->budget->id}/movements?type=reserva")->assertJsonCount(1, 'data');
        $this->getJson("/api/budgets/{$this->budget->id}/movements?invoice_intake_id={$intake->id}")->assertJsonCount(2, 'data');
        $this->getJson("/api/budgets/{$this->budget->id}/movements?type=invalido")->assertStatus(422)->assertJsonValidationErrors('type');
        $this->getJson("/api/budgets/{$this->budget->id}/movements?from=2001-01-01&to=2001-12-31")->assertJsonCount(0, 'data');
    }

    public function test_spending_groups_by_product_type_and_supplier(): void
    {
        $intake = $this->seedActivity();

        $byProduct = $this->getJson("/api/budgets/{$this->budget->id}/spending?group_by=product")->assertOk();
        $byProduct->assertJsonPath('total', '150000.00');
        $this->assertEqualsCanonicalizing(
            [['Cable', 10, '50000.00'], ['Maqueta', 1, '100000.00']],
            collect($byProduct->json('data'))->map(fn ($r) => [$r['label'], $r['quantity'], $r['amount']])->all()
        );

        $byType = collect($this->getJson("/api/budgets/{$this->budget->id}/spending?group_by=product_type")->json('data'))->pluck('amount', 'label');
        $this->assertSame('50000.00', $byType['insumo']);
        $this->assertSame('100000.00', $byType['maqueta']);

        $bySupplier = $this->getJson("/api/budgets/{$this->budget->id}/spending?group_by=supplier")->assertOk();
        $this->assertSame([['Proveedor Uno', '150000.00']], collect($bySupplier->json('data'))->map(fn ($r) => [$r['label'], $r['amount']])->all());

        // Un ingreso anulado deja de contar.
        $this->postJson("/api/invoice-intakes/{$intake->id}/cancel")->assertOk();
        $this->getJson("/api/budgets/{$this->budget->id}/spending")->assertJsonPath('total', '0.00')->assertJsonCount(0, 'data');

        $this->getJson("/api/budgets/{$this->budget->id}/spending?group_by=otro")->assertStatus(422)->assertJsonValidationErrors('group_by');
    }

    public function test_monthly_series_has_twelve_months_from_the_ledger(): void
    {
        $this->seedActivity();
        $year = (int) now()->format('Y');
        $month = (int) now()->format('n');

        $data = $this->getJson("/api/budgets/{$this->budget->id}/monthly")->assertOk()->assertJsonPath('year', $year)->json('data');

        $this->assertCount(12, $data);
        $this->assertSame(['month' => $month, 'reserved' => '200000.00', 'consumed' => '150000.00', 'restored' => '0.00'], $data[$month - 1]);
        $others = collect($data)->where('month', '!=', $month);
        $this->assertSame(['0.00'], $others->pluck('reserved')->unique()->values()->all());

        $empty = $this->getJson("/api/budgets/{$this->budget->id}/monthly?year=2001")->assertOk()->json('data');
        $this->assertSame(['0.00'], collect($empty)->pluck('consumed')->unique()->values()->all());
    }

    public function test_roles_are_enforced_and_area_director_sees_all_careers(): void
    {
        $paths = ['/api/budgets/summary', "/api/budgets/{$this->budget->id}/movements", "/api/budgets/{$this->budget->id}/spending", "/api/budgets/{$this->budget->id}/monthly"];

        foreach ([User::ROLE_WAREHOUSE, User::ROLE_TEACHER] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role, 'is_active' => true]));
            foreach ($paths as $path) {
                $this->getJson($path)->assertForbidden();
            }
        }

        $other = AnnualBudget::factory()->create(['career_id' => Career::factory()->create()->id]);
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_DIRECTOR, 'is_active' => true]));

        $ids = collect($this->getJson('/api/budgets/summary')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($this->budget->id));
        $this->assertTrue($ids->contains($other->id));
        foreach (array_slice($paths, 1) as $path) {
            $this->getJson($path)->assertOk();
        }
    }
}
