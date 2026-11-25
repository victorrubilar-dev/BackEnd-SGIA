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
        $this->assertSame(['Resumen', 'Movimientos', 'Info'], array_keys($this->readXlsx($response)));

        $summary = collect($this->xlsxRecords($response, 'Resumen'))->keyBy('Año');
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
        $this->assertSame(2091, $consume['Año']);
        $this->assertSame($director->name, $consume['Usuario']);
        $this->assertNotNull($movements->firstWhere('Tipo', 'Liberación de reserva'));
        $this->assertSame("Carrera: {$career->name}", $this->xlsxInfo($response)['Filtros']);
    }

    public function test_year_filter_excludes_other_years(): void
    {
        $director = $this->actingAsRole(User::ROLE_DIRECTOR);
        [$career] = $this->budgets($director);

        $response = $this->get("/api/budgets/export?career_id={$career->id}&year=2090")->assertOk();
        $this->assertSame([2090], array_column($this->xlsxRecords($response, 'Resumen'), 'Año'));
        $this->assertSame(['Liberación de reserva'], array_column($this->xlsxRecords($response, 'Movimientos'), 'Tipo'));
        $this->assertStringContainsString('Año: 2090', $this->xlsxInfo($response)['Filtros']);
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
    }
}
