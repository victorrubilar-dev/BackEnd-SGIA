<?php

namespace Tests\Feature;

use App\Models\Career;
use App\Models\EquipmentUnit;
use App\Models\IncidentReport;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\MaintenanceRecord;
use App\Models\Product;
use App\Models\StockAlert;
use App\Models\Storeroom;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ReadsXlsx;
use Tests\TestCase;

/**
 * «Informe del período» `GET /api/reports/period` (docs/centro-reportes.md, decisión 10).
 */
class PeriodReportTest extends TestCase
{
    use DatabaseTransactions;
    use ReadsXlsx;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function actingAsRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true, ...$attributes]);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_combines_the_period_sheets_with_a_general_summary(): void
    {
        $this->actingAsRole(User::ROLE_DIRECTOR);
        $career = Career::factory()->create();
        $product = Product::factory()->create(['career_id' => $career->id, 'type' => Product::TYPE_EQUIPMENT]);
        $unit = EquipmentUnit::factory()->create(['product_id' => $product->id]);

        $loan = Loan::factory()->processed()->create(['career_id' => $career->id, 'loan_date' => '2036-03-10']);
        $item = LoanItem::create(['loan_id' => $loan->id, 'product_id' => $product->id, 'name' => $product->name, 'quantity' => 1]);
        $item->units()->attach($unit->id);
        $outside = Loan::factory()->create(['career_id' => $career->id, 'loan_date' => '2036-08-10']);
        MaintenanceRecord::factory()->create(['equipment_unit_id' => $unit->id, 'started_at' => '2036-04-01 10:00:00', 'repair_cost' => 25000]);
        $incident = IncidentReport::factory()->create(['product_id' => $product->id, 'severity' => IncidentReport::SEVERITY_HIGH, 'report_date' => '2036-05-02']);
        $alert = StockAlert::create(['product_id' => $product->id, 'alert_type' => StockAlert::TYPE_CRITICAL, 'current_stock' => 0, 'stock_minimo' => 1, 'message' => 'Período', 'is_resolved' => false]);
        $alert->forceFill(['created_at' => '2036-06-01 09:00:00'])->save();

        $response = $this->get("/api/reports/period?from=2036-01-01&to=2036-06-30&career_id={$career->id}")->assertOk();
        $this->assertMatchesRegularExpression('/filename="informe-periodo-\d{8}-\d{6}\.xlsx"/', (string) $response->headers->get('Content-Disposition'));

        $sheets = array_keys($this->readXlsx($response));
        $this->assertSame('Resumen general', $sheets[0]);
        foreach (['Préstamos', 'Detalle', 'Mantenciones', 'Informes', 'Alertas', 'Info'] as $name) {
            $this->assertContains($name, $sheets);
        }

        $this->assertSame([$loan->code], array_column($this->xlsxRecords($response, 'Préstamos'), 'Código'), 'solo el préstamo del período');
        $this->assertNotContains($outside->code, array_column($this->xlsxRecords($response, 'Préstamos'), 'Código'));
        $this->assertSame([$incident->code], array_column($this->xlsxRecords($response, 'Informes'), 'Código'));
        $this->assertCount(1, $this->xlsxRecords($response, 'Mantenciones'));
        $this->assertSame(['Período'], array_column($this->xlsxRecords($response, 'Alertas'), 'Mensaje'));

        $overview = collect($this->xlsxRecords($response, 'Resumen general'))->keyBy('Indicador');
        $this->assertSame(1, $overview['Préstamos del período']['Cantidad']);
        $this->assertSame(1, $overview['Unidades de equipo prestadas']['Cantidad']);
        $this->assertSame(1, $overview['Mantenciones iniciadas']['Cantidad']);
        $this->assertSame(25000, $overview['Mantenciones iniciadas']['Monto']);
        $this->assertSame(1, $overview['Severidad: Crítica']['Cantidad']);
        $this->assertSame(1, $overview['Alertas críticas']['Cantidad']);

        $info = $this->xlsxInfo($response);
        $this->assertSame('Informe del período', $info['Reporte']);
        $this->assertStringContainsString("Carrera: {$career->name}", $info['Filtros']);
        $this->assertStringStartsWith('Préstamos de todos los pañoles', $info['Alcance']);
    }

    public function test_warehouse_scope_preview_and_validation(): void
    {
        $storeroom = Storeroom::factory()->create(['name' => 'Pañol del informe']);
        $this->actingAsRole(User::ROLE_WAREHOUSE, ['storeroom_id' => $storeroom->id]);
        $mine = Loan::factory()->create(['storeroom_id' => $storeroom->id, 'loan_date' => '2036-03-10']);
        $other = Loan::factory()->create(['storeroom_id' => Storeroom::factory()->create()->id, 'loan_date' => '2036-03-10']);

        $json = $this->getJson('/api/reports/period?from=2036-03-01&to=2036-03-31&preview=1')->assertOk()->json();
        $loans = collect($json['sheets'])->firstWhere('name', 'Préstamos');
        $codes = array_column($loans['rows'], 0);
        $this->assertContains($mine->code, $codes);
        $this->assertNotContains($other->code, $codes, 'PAN-01 solo ve los préstamos de su pañol');
        $this->assertSame('Préstamos del pañol Pañol del informe; mantenciones, novedades y alertas de todo el sistema', collect($json['info'])->firstWhere(0, 'Alcance')[1]);

        $this->getJson('/api/reports/period')->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
        $this->getJson('/api/reports/period?from=2036-03-31&to=2036-03-01')->assertUnprocessable()->assertJsonValidationErrors('to');

        foreach ([User::ROLE_ADMIN, User::ROLE_TEACHER] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/reports/period?from=2036-03-01&to=2036-03-31')->assertForbidden();
        }
    }
}
