<?php

namespace Tests\Feature;

use App\Models\IncidentReport;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ReadsXlsx;
use Tests\TestCase;

/**
 * Reporte Excel de informes de novedades `GET /api/incident-reports/export` (docs/reportes-excel.md).
 */
class IncidentReportExportTest extends TestCase
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

    public function test_exports_reports_with_the_inbox_filters(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $tag = 'NOV'.fake()->unique()->numerify('####');
        $critical = IncidentReport::factory()->create([
            'title' => "{$tag} Proyector sin imagen",
            'severity' => IncidentReport::SEVERITY_HIGH,
            'problems' => [['type' => IncidentReport::PROBLEM_EQUIPMENT_FAILURE, 'description' => 'No enciende'], ['type' => IncidentReport::PROBLEM_OTHER, 'description' => 'Cable suelto']],
        ]);
        IncidentReport::factory()->create(['title' => "{$tag} Mouse gastado", 'severity' => IncidentReport::SEVERITY_LOW]);

        $response = $this->get("/api/incident-reports/export?search={$tag}&severity=critica")->assertOk();
        $this->assertMatchesRegularExpression('/filename="novedades-\d{8}-\d{6}\.xlsx"/', (string) $response->headers->get('Content-Disposition'));
        $rows = $this->xlsxRecords($response, 'Informes');
        $this->assertSame([$critical->code], array_column($rows, 'Código'));
        $this->assertSame('Crítica', $rows[0]['Severidad']);
        $this->assertSame('Reportado', $rows[0]['Estado']);
        $this->assertSame('Falla de equipos / instrumento / software: No enciende; Otros: Cable suelto', $rows[0]['Problemas']);
        $this->assertSame($critical->reporter->name, $rows[0]['Reportado por']);
        $this->assertSame("Búsqueda: {$tag}; Severidad: Crítica", $this->xlsxInfo($response)['Filtros']);
    }

    public function test_roles_and_validation(): void
    {
        foreach ([User::ROLE_ADMIN, User::ROLE_TEACHER] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/incident-reports/export')->assertForbidden();
        }

        $this->actingAsRole(User::ROLE_DIRECTOR);
        $this->getJson('/api/incident-reports/export?status=perdido')->assertUnprocessable()->assertJsonValidationErrors('status');
    }
}
