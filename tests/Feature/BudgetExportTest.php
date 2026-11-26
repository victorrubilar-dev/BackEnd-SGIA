<?php

namespace Tests\Feature;

use App\Models\AnnualBudget;
use App\Models\BudgetMovement;
use App\Models\Career;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ReadsXlsx;
use Tests\TestCase;

/**
 * Reporte Excel de presupuestos `GET /api/budgets/export` (docs/reportes-excel.md).
 */
class BudgetExportTest extends TestCase
{
    use DatabaseTransactions;
    use ReadsXlsx;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * @return array{0: Career, 1: AnnualBudget, 2: AnnualBudget}
     */
    private function budgets(User $director): array
    {
        $career = Career::factory()->create();
        $current = AnnualBudget::factory()->create(['career_id' => $career->id, 'year' => 2091, 'initial_amount' => 1000000, 'available_amount' => 700000, 'reserved_amount' => 100000]);
        $previous = AnnualBudget::factory()->create(['career_id' => $career->id, 'year' => 2090, 'initial_amount' => 500000, 'available_amount' => 500000, 'reserved_amount' => 0]);
        BudgetMovement::create(['annual_budget_id' => $current->id, 'type' => BudgetMovement::TYPE_INITIAL, 'amount' => 1000000, 'user_id' => $director->id]);
        BudgetMovement::create(['annual_budget_id' => $current->id, 'type' => BudgetMovement::TYPE_CONSUME, 'amount' => 300000, 'user_id' => $director->id, 'notes' => 'Factura de prueba']);
        BudgetMovement::create(['annual_budget_id' => $previous->id, 'type' => BudgetMovement::TYPE_RELEASE, 'amount' => 20000, 'user_id' => $director->id]);

        return [$career, $current, $previous];
    }

    public function test_without_year_exports_every_year_of_the_career(): void
    {
        $director = $this->actingAsRole(User::ROLE_DIRECTOR);
        [$career] = $this->budgets($director);
        AnnualBudget::factory()->create(['year' => 2091]);

        $response = $this->get("/api/budgets/export?career_id={$career->id}")->assertOk();
        $this->assertMatchesRegularExpression('/filename="presupuestos-\d{8}-\d{6}\.xlsx"/', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame(['Presupuestos', 'Movimientos', 'Resumen', 'Info'], array_keys($this->readXlsx($response)));

        $summary = collect($this->xlsxRecords($response, 'Presupuestos'))->keyBy('Año');
        $this->assertSame([2091, 2090], $summary->keys()->all());
        $this->assertSame($career->name, $summary[2091]['Carrera']);
        $this->assertSame(1000000, $summary[2091]['Inicial']);
        $this->assertSame(100000, $summary[2091]['Reservado']);
        $this->assertSame(300000, $summary[2091]['Gastado']);
        $this->assertSame(600000, $summary[2091]['Disponible']);
        $this->assertSame(40, $summary[2091]['Ejecución %']);

        $movements = collect($this->xlsxRecords($response, 'Movimientos'));
        $this->assertCount(3, $movements);
        $consume = $movements->firstWhere('Tipo', 'Consumo');
        $this->assertSame(300000, $consume['Monto']);
        $this->assertSame(2091, $consume['Año presupuesto']);
        $this->assertSame((int) now()->year, $consume['Año'], 'año del movimiento');
        $this->assertSame($director->name, $consume['Usuario']);
        $this->assertNotNull($movements->firstWhere('Tipo', 'Liberación de reserva'));
        $this->assertSame("Carrera: {$career->name}", $this->xlsxInfo($response)['Filtros']);
    }

    public function test_year_filter_excludes_other_years(): void
    {
        $director = $this->actingAsRole(User::ROLE_DIRECTOR);
        [$career] = $this->budgets($director);

        $response = $this->get("/api/budgets/export?career_id={$career->id}&year=2090")->assertOk();
        $this->assertSame([2090], array_column($this->xlsxRecords($response, 'Presupuestos'), 'Año'));
        $this->assertSame(['Liberación de reserva'], array_column($this->xlsxRecords($response, 'Movimientos'), 'Tipo'));
        $this->assertStringContainsString('Año: 2090', $this->xlsxInfo($response)['Filtros']);
    }

    public function test_period_bounds_movements_summary_by_type_and_career_and_budget_totals(): void
    {
        $director = $this->actingAsRole(User::ROLE_DIRECTOR);
        [$career, $current, $previous] = $this->budgets($director);
        $at = fn (string $type, string $date) => BudgetMovement::where('type', $type)->whereIn('annual_budget_id', [$current->id, $previous->id])->firstOrFail()->forceFill(['created_at' => $date])->save();
        $at(BudgetMovement::TYPE_INITIAL, '2026-02-28 23:30:00');
        $at(BudgetMovement::TYPE_CONSUME, '2026-03-05 10:00:00');
        $at(BudgetMovement::TYPE_RELEASE, '2026-03-31 22:00:00');

        $response = $this->get("/api/budgets/export?career_id={$career->id}&from=2026-03-01&to=2026-03-31")->assertOk();
        $this->assertSame(['Presupuestos', 'Movimientos', 'Resumen', 'Info'], array_keys($this->readXlsx($response)));
        $this->assertEqualsCanonicalizing(['Consumo', 'Liberación de reserva'], array_column($this->xlsxRecords($response, 'Movimientos'), 'Tipo'));
        $this->assertCount(2, $this->xlsxRecords($response, 'Presupuestos'), 'el período no acota los presupuestos, solo los movimientos');
        $this->assertStringContainsString('Movimientos desde: 2026-03-01; Movimientos hasta: 2026-03-31', $this->xlsxInfo($response)['Filtros']);

        $summary = $this->xlsxSummary($response);
        $this->assertContains('Tipo|Consumo|1|300000', $summary);
        $this->assertContains('Tipo|Liberación de reserva|1|20000', $summary);
        $this->assertContains("Carrera|{$career->name}|2|320000", $summary);

        // Totales de «Presupuestos»: 2091 (1.000.000 inicial, 100.000 reservado, 300.000 gastado) + 2090 (500.000 sin uso).
        $this->assertSame(['Año' => 'Total', 'Inicial' => 1500000, 'Reservado' => 100000, 'Gastado' => 300000, 'Saldo' => 1200000, 'Disponible' => 1100000], $this->xlsxTotals($response, 'Presupuestos'));
        $this->assertNull($this->xlsxTotals($response, 'Movimientos'));
    }

    public function test_roles_and_validation(): void
    {
        foreach ([User::ROLE_ADMIN, User::ROLE_WAREHOUSE, User::ROLE_TEACHER] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/budgets/export')->assertForbidden();
        }

        $this->actingAsRole(User::ROLE_DIRECTOR);
        $this->getJson('/api/budgets/export?year=dos-mil')->assertUnprocessable()->assertJsonValidationErrors('year');
        $this->getJson('/api/budgets/export?career_id=abc')->assertUnprocessable()->assertJsonValidationErrors('career_id');
        $this->getJson('/api/budgets/export?from=2026-03-31&to=2026-03-01')->assertUnprocessable()->assertJsonValidationErrors('to');
    }
}
