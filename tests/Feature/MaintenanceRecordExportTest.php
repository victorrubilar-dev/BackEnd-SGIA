<?php

namespace Tests\Feature;

use App\Models\Career;
use App\Models\EquipmentUnit;
use App\Models\MaintenanceRecord;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ReadsXlsx;
use Tests\TestCase;

/**
 * Reporte Excel de mantenciones `GET /api/maintenance-records/export` (docs/reportes-excel.md).
 */
class MaintenanceRecordExportTest extends TestCase
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

    public function test_exports_records_filtered_by_career_and_dates(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $career = Career::factory()->create();
        $unit = EquipmentUnit::factory()->create(['product_id' => Product::factory()->create(['career_id' => $career->id, 'type' => Product::TYPE_EQUIPMENT])->id]);
        $march = MaintenanceRecord::factory()->create(['equipment_unit_id' => $unit->id, 'started_at' => '2026-03-10 10:00:00', 'technical_notes' => 'Limpieza & ajuste', 'repair_cost' => 15000]);
        MaintenanceRecord::factory()->create(['equipment_unit_id' => $unit->id, 'started_at' => '2026-05-10 10:00:00']);
        MaintenanceRecord::factory()->create(['started_at' => '2026-03-11 10:00:00']);

        $response = $this->get("/api/maintenance-records/export?career_id={$career->id}&from=2026-03-01&to=2026-03-31")->assertOk();
        $rows = $this->xlsxRecords($response, 'Mantenciones');
        $this->assertCount(1, $rows);
        $this->assertSame($unit->code, $rows[0]['Unidad']);
        $this->assertSame($career->name, $rows[0]['Carrera']);
        $this->assertSame('Preventiva', $rows[0]['Tipo']);
        $this->assertSame('Limpieza & ajuste', $rows[0]['Notas técnicas']);
        $this->assertSame(15000, $rows[0]['Costo']);
        $this->assertSame("Carrera: {$career->name}; Desde: 2026-03-01; Hasta: 2026-03-31", $this->xlsxInfo($response)['Filtros']);
        $this->assertNotNull($march);
    }

    public function test_roles_and_validation(): void
    {
        foreach ([User::ROLE_ADMIN, User::ROLE_TEACHER] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/maintenance-records/export')->assertForbidden();
        }

        $this->actingAsRole(User::ROLE_DIRECTOR);
        $this->getJson('/api/maintenance-records/export?from=ayer')->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->getJson('/api/maintenance-records/export?from=2026-05-01&to=2026-04-01')->assertUnprocessable()->assertJsonValidationErrors('to');
    }
}
