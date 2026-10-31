<?php

namespace Tests\Feature;

use App\Models\Cajon;
use App\Models\Career;
use App\Models\EquipmentUnit;
use App\Models\IncidentReport;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockAlert;
use App\Models\User;
use App\Services\EquipmentUnitService;
use App\Services\MetricsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MetricsGraphQLTest extends TestCase
{
    use DatabaseTransactions;

    /** Documentos tal como los envía packages/api-client/src/services/metrics.ts. */
    private const KPIS = 'query GetExecutiveKpis($timeRange: TimeRange) { executiveKpis(timeRange: $timeRange) { activosDisponibles activosEnPrestamo activosEnTaller activosDeBaja prestamosActivosHoy tasaAtrasosPct alertasCriticasNoResueltas tasaRotacionMensualPct } }';

    private const METRIC = 'query GetCustomMetric($input: MetricQueryInput!) { customMetric(input: $input) { entity metric timeRange chartType summary points { label value secondaryValue category } } }';

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();

        $this->director = User::factory()->create(['role' => User::ROLE_DIRECTOR, 'is_active' => true]);
        Sanctum::actingAs($this->director);
    }

    private function gql(string $query, array $variables = []): TestResponse
    {
        return $this->postJson('/api/metrics', ['query' => $query, 'variables' => $variables]);
    }

    private function metric(array $input): TestResponse
    {
        return $this->gql(self::METRIC, ['input' => $input + ['timeRange' => 'LAST_30_DAYS', 'chartType' => 'BAR']]);
    }

    private function seedActivity(): Product
    {
        $teacher = User::factory()->create(['role' => User::ROLE_TEACHER, 'is_active' => true, 'area' => 'Ciberseguridad', 'name' => 'Profe Métrica']);
        $product = Product::factory()->create(['area' => 'Redes', 'quantity' => 20, 'quantity_in_repair' => 2, 'quantity_written_off' => 1]);

        $make = function (array $attrs, int $qty) use ($teacher, $product) {
            $loan = Loan::factory()->processed()->create($attrs + ['requested_by' => $teacher->id, 'subject' => 'Redes I', 'loan_date' => today()->subDays(3)->toDateString(), 'due_date' => today()->subDays(2)->toDateString()]);
            LoanItem::create(['loan_id' => $loan->id, 'product_id' => $product->id, 'name' => $product->name, 'quantity' => $qty]);

            return $loan;
        };

        $make(['returned_at' => today()->subDays(3)], 2);           // a tiempo
        $make(['returned_at' => today()], 4);                       // devuelto tarde
        $make([], 1);                                               // atrasado (vencido, sin devolver)
        $make(['due_date' => today()->addDays(5)->toDateString()], 3); // activo

        StockAlert::create(['product_id' => $product->id, 'alert_type' => 'critical', 'current_stock' => 1, 'stock_minimo' => 5, 'message' => 'x', 'is_resolved' => false]);
        IncidentReport::factory()->create(['product_id' => $product->id, 'severity' => 'critica']);

        return $product;
    }

    public function test_executive_kpis_use_the_frontend_document(): void
    {
        $this->seedActivity();

        $data = $this->gql(self::KPIS, ['timeRange' => 'LAST_30_DAYS'])->assertOk()->assertJsonMissingPath('errors')->json('data.executiveKpis');

        $this->assertSame(
            ['activosDisponibles', 'activosEnPrestamo', 'activosEnTaller', 'activosDeBaja', 'prestamosActivosHoy', 'tasaAtrasosPct', 'alertasCriticasNoResueltas', 'tasaRotacionMensualPct'],
            array_keys($data)
        );
        $this->assertGreaterThanOrEqual(4, $data['activosEnPrestamo']); // 1 atrasado + 3 activo = 4 unidades fuera
        $this->assertGreaterThanOrEqual(1, $data['alertasCriticasNoResueltas']);
        $this->assertIsNumeric($data['tasaAtrasosPct']);
    }

    public function test_kpis_default_to_last_30_days_and_reject_custom(): void
    {
        $this->gql('{ executiveKpis { activosDisponibles } }')->assertOk()->assertJsonMissingPath('errors');
        $this->gql(self::KPIS, ['timeRange' => 'CUSTOM'])->assertOk()->assertJsonPath('errors.0.message', 'executiveKpis no admite CUSTOM; usa un período predefinido.');
    }

    public function test_every_entity_metric_and_groupby_combination_resolves(): void
    {
        $this->seedActivity();
        $combos = 0;

        foreach (MetricsService::GROUP_BY as $entity => $groups) {
            foreach ([null, ...$groups] as $groupBy) {
                foreach (['COUNT', 'TOTAL', 'AVERAGE', 'RATE'] as $metric) {
                    $response = $this->metric(['entity' => $entity, 'metric' => $metric, 'groupBy' => $groupBy])->assertOk();

                    $this->assertNull($response->json('errors'), "{$entity} {$metric} ".($groupBy ?? '-').': '.json_encode($response->json('errors')));
                    $this->assertIsArray($response->json('data.customMetric.points'));
                    $combos++;
                }
            }
        }

        $this->assertGreaterThan(80, $combos);
    }

    public function test_loans_by_teacher_counts_and_sums_units(): void
    {
        $this->seedActivity();

        $count = $this->metric(['entity' => 'LOANS', 'metric' => 'COUNT', 'groupBy' => 'teacher'])->json('data.customMetric.points');
        $this->assertEquals(4, collect($count)->firstWhere('label', 'Profe Métrica')['value']);

        $total = collect($this->metric(['entity' => 'LOANS', 'metric' => 'TOTAL', 'groupBy' => 'teacher'])->json('data.customMetric.points'))->firstWhere('label', 'Profe Métrica');
        $this->assertEquals(10, $total['value']);

        $avg = collect($this->metric(['entity' => 'LOANS', 'metric' => 'AVERAGE', 'groupBy' => 'teacher'])->json('data.customMetric.points'))->firstWhere('label', 'Profe Métrica');
        $this->assertEquals(2.5, $avg['value']);
    }

    public function test_loan_punctuality_rate_matches_the_donut_the_frontend_expects(): void
    {
        $this->seedActivity();

        $points = collect($this->metric(['entity' => 'LOANS', 'metric' => 'RATE', 'chartType' => 'DOUGHNUT'])->json('data.customMetric.points'));

        $this->assertSame(['Devuelto a tiempo', 'Activo', 'Atrasado'], $points->pluck('label')->all());
        $this->assertEqualsWithDelta(100.0, $points->sum('value'), 0.3);
        $this->assertGreaterThan(0, $points->firstWhere('label', 'Atrasado')['value']);
    }

    public function test_time_series_has_weekly_buckets_for_30_days_and_monthly_for_longer(): void
    {
        $this->seedActivity();

        $weeks = $this->metric(['entity' => 'LOANS', 'metric' => 'COUNT', 'chartType' => 'LINE'])->json('data.customMetric.points');
        $this->assertSame('Semana 1', $weeks[0]['label']);
        $this->assertSame(5, count($weeks));
        $this->assertEquals(4, array_sum(array_column($weeks, 'value')));

        $months = $this->metric(['entity' => 'LOANS', 'metric' => 'COUNT', 'timeRange' => 'YEAR_TO_DATE'])->json('data.customMetric.points');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}$/', $months[0]['label']);
    }

    public function test_supplies_and_equipment_rankings_include_remaining_stock(): void
    {
        $product = $this->seedActivity();

        $point = collect($this->metric(['entity' => 'EQUIPMENT', 'metric' => 'TOTAL'])->json('data.customMetric.points'))->firstWhere('label', $product->name);
        $this->assertEquals(20, $point['secondaryValue']);

        $condition = collect($this->metric(['entity' => 'EQUIPMENT', 'metric' => 'TOTAL', 'groupBy' => 'physical_condition'])->json('data.customMetric.points'));
        $this->assertEqualsCanonicalizing(['Disponible', 'En taller', 'Dado de baja'], $condition->pluck('label')->all());
    }

    /**
     * @return array{0: Product, 1: Location, 2: Location}
     */
    private function unitsInTwoRooms(string $type = Product::TYPE_EQUIPMENT): array
    {
        $roomA = Location::factory()->create(['nombre' => 'Sala Métrica A']);
        $roomB = Location::factory()->create(['nombre' => 'Sala Métrica B']);
        $cajonA = Cajon::factory()->create(['location_id' => $roomA->id]);
        $cajonB = Cajon::factory()->create(['location_id' => $roomB->id]);
        $product = Product::factory()->create(['type' => $type, 'quantity' => 0, 'cajon_id' => $cajonA->id, 'location_id' => $roomA->id]);

        EquipmentUnit::factory()->count(2)->create(['product_id' => $product->id, 'cajon_id' => $cajonA->id]);
        EquipmentUnit::factory()->create(['product_id' => $product->id, 'cajon_id' => $cajonB->id]);
        EquipmentUnit::factory()->inRepair()->create(['product_id' => $product->id, 'cajon_id' => $cajonB->id]);
        EquipmentUnit::factory()->writtenOff()->create(['product_id' => $product->id, 'cajon_id' => $cajonB->id]);
        app(EquipmentUnitService::class)->syncProductCounters($product);

        return [$product, $roomA, $roomB];
    }

    public function test_equipment_location_counts_units_per_room_excluding_written_off(): void
    {
        $this->unitsInTwoRooms();

        $points = collect($this->metric(['entity' => 'EQUIPMENT', 'metric' => 'COUNT', 'groupBy' => 'location', 'limit' => 50])->assertJsonMissingPath('errors')->json('data.customMetric.points'))->pluck('value', 'label');

        $this->assertEquals(2, $points['Sala Métrica A']);
        $this->assertEquals(2, $points['Sala Métrica B']); // 1 disponible + 1 en reparación; la dada de baja no cuenta
    }

    public function test_equipment_physical_condition_counts_units_by_status(): void
    {
        $this->unitsInTwoRooms();
        $before = collect($this->metric(['entity' => 'EQUIPMENT', 'metric' => 'TOTAL', 'groupBy' => 'physical_condition'])->json('data.customMetric.points'))->pluck('value', 'label');

        $this->assertGreaterThanOrEqual(3, $before['Disponible']);
        $this->assertGreaterThanOrEqual(1, $before['En taller']);
        $this->assertGreaterThanOrEqual(1, $before['Dado de baja']);
    }

    public function test_scale_models_entity_counts_only_scale_models(): void
    {
        $this->unitsInTwoRooms(Product::TYPE_EQUIPMENT);
        $this->unitsInTwoRooms(Product::TYPE_SCALE_MODEL);

        $points = fn (string $entity) => collect($this->metric(['entity' => $entity, 'metric' => 'COUNT', 'groupBy' => 'location', 'limit' => 50])->assertJsonMissingPath('errors')->json('data.customMetric.points'))->pluck('value', 'label');

        // Ambas entidades usan las mismas salas, pero cada una solo suma sus unidades (2 por sala).
        $this->assertEquals(2, $points('SCALE_MODELS')['Sala Métrica A']);
        $this->assertEquals(2, $points('EQUIPMENT')['Sala Métrica A']);

        $summary = $this->metric(['entity' => 'SCALE_MODELS', 'metric' => 'COUNT', 'groupBy' => 'physical_condition'])->json('data.customMetric.summary');
        $this->assertStringContainsString('Maquetas', $summary);
        $this->metric(['entity' => 'SCALE_MODELS', 'metric' => 'COUNT', 'groupBy' => 'category'])->assertJsonMissingPath('errors');
        $this->metric(['entity' => 'SCALE_MODELS', 'metric' => 'COUNT', 'groupBy' => 'turnover'])->assertJsonPath('errors.0.message', 'groupBy "turnover" no es válido para SCALE_MODELS. Opciones: hardware_category, location, physical_condition.');
    }

    public function test_scale_models_ranking_is_filtered_by_type(): void
    {
        $model = Product::factory()->scaleModel()->create(['quantity' => 3]);
        $equipment = Product::factory()->create(['quantity' => 3]);
        $loan = Loan::factory()->processed()->create(['loan_date' => today()->subDay()->toDateString()]);
        LoanItem::create(['loan_id' => $loan->id, 'product_id' => $model->id, 'name' => $model->name, 'quantity' => 1]);
        LoanItem::create(['loan_id' => $loan->id, 'product_id' => $equipment->id, 'name' => $equipment->name, 'quantity' => 1]);

        $labels = fn (string $entity) => collect($this->metric(['entity' => $entity, 'metric' => 'COUNT', 'limit' => 50])->json('data.customMetric.points'))->pluck('label');

        $this->assertContains($model->name, $labels('SCALE_MODELS'));
        $this->assertNotContains($equipment->name, $labels('SCALE_MODELS'));
        $this->assertNotContains($model->name, $labels('EQUIPMENT'));
    }

    public function test_maintenance_workshop_uses_the_unit_room_when_report_has_a_unit(): void
    {
        [$product, , $roomB] = $this->unitsInTwoRooms();
        $unitInB = $product->units()->whereHas('cajon', fn ($q) => $q->where('location_id', $roomB->id))->first();
        IncidentReport::factory()->create(['product_id' => $product->id, 'equipment_unit_id' => $unitInB->id]);

        $points = collect($this->metric(['entity' => 'MAINTENANCE', 'metric' => 'COUNT', 'groupBy' => 'workshop', 'limit' => 50])->json('data.customMetric.points'))->pluck('value', 'label');

        $this->assertEquals(1, $points['Sala Métrica B']);
        $this->assertArrayNotHasKey('Sala Métrica A', $points->all());
    }

    public function test_kpis_match_the_unit_counts_of_a_product(): void
    {
        $before = $this->gql(self::KPIS, ['timeRange' => 'LAST_30_DAYS'])->json('data.executiveKpis');
        $this->unitsInTwoRooms();
        $after = $this->gql(self::KPIS, ['timeRange' => 'LAST_30_DAYS'])->json('data.executiveKpis');

        $this->assertSame(3, $after['activosDisponibles'] - $before['activosDisponibles']);
        $this->assertSame(1, $after['activosEnTaller'] - $before['activosEnTaller']);
        $this->assertSame(1, $after['activosDeBaja'] - $before['activosDeBaja']);
    }

    public function test_equipment_and_supplies_only_include_their_product_type(): void
    {
        $equipment = $this->seedActivity();
        $supply = Product::factory()->supply()->create(['quantity' => 40, 'area' => 'Consumibles']);
        $loan = Loan::factory()->processed()->create(['loan_date' => today()->subDay()->toDateString()]);
        LoanItem::create(['loan_id' => $loan->id, 'product_id' => $supply->id, 'name' => $supply->name, 'quantity' => 6]);

        $labels = fn (array $input) => collect($this->metric($input + ['limit' => 50])->json('data.customMetric.points'))->pluck('label');

        $supplies = $labels(['entity' => 'SUPPLIES', 'metric' => 'TOTAL']);
        $this->assertContains($supply->name, $supplies);
        $this->assertNotContains($equipment->name, $supplies);

        $ranking = $labels(['entity' => 'EQUIPMENT', 'metric' => 'TOTAL']);
        $this->assertContains($equipment->name, $ranking);
        $this->assertNotContains($supply->name, $ranking);

        $this->assertNotContains('Consumibles', $labels(['entity' => 'EQUIPMENT', 'metric' => 'COUNT', 'groupBy' => 'hardware_category']));
        $this->assertContains('Consumibles', $labels(['entity' => 'SUPPLIES', 'metric' => 'COUNT', 'groupBy' => 'category']));

        $point = collect($this->metric(['entity' => 'SUPPLIES', 'metric' => 'TOTAL'])->json('data.customMetric.points'))->firstWhere('label', $supply->name);
        $this->assertEquals(6, $point['value']);
        $this->assertEquals(40, $point['secondaryValue']);
    }

    public function test_careers_group_by_level_uses_the_career_catalog(): void
    {
        $this->seedActivity(); // 4 préstamos de un docente de "Ciberseguridad"
        Career::factory()->create(['name' => 'Ciberseguridad', 'level' => Career::LEVEL_PROFESSIONAL]);

        $other = User::factory()->create(['role' => User::ROLE_TEACHER, 'is_active' => true, 'area' => 'Sin catálogo']);
        Loan::factory()->processed()->create(['requested_by' => $other->id, 'loan_date' => today()->subDay()->toDateString()]);

        $points = collect($this->metric(['entity' => 'CAREERS', 'metric' => 'COUNT', 'groupBy' => 'level'])->json('data.customMetric.points'))->pluck('value', 'label');

        $this->assertGreaterThanOrEqual(4, $points['Profesional']);
        $this->assertGreaterThanOrEqual(1, $points['Sin nivel']);
    }

    public function test_invalid_group_by_custom_range_and_unknown_fields_return_graphql_errors(): void
    {
        $this->metric(['entity' => 'CAREERS', 'metric' => 'COUNT', 'groupBy' => 'grade'])
            ->assertOk()->assertJsonPath('errors.0.message', 'groupBy "grade" no es válido para CAREERS. Opciones: specialty, level.');

        $this->metric(['entity' => 'LOANS', 'metric' => 'COUNT', 'timeRange' => 'CUSTOM'])
            ->assertJsonPath('errors.0.message', 'CUSTOM requiere dateFrom (y opcionalmente dateTo).');

        $this->metric(['entity' => 'LOANS', 'metric' => 'COUNT', 'timeRange' => 'CUSTOM', 'dateFrom' => '2026-13-45'])
            ->assertJsonPath('errors.0.message', 'dateFrom / dateTo deben tener formato YYYY-MM-DD.');

        $this->metric(['entity' => 'LOANS', 'metric' => 'COUNT', 'timeRange' => 'CUSTOM', 'dateFrom' => today()->subDays(10)->toDateString(), 'dateTo' => today()->toDateString()])
            ->assertJsonMissingPath('errors');

        $this->gql('{ customMetric { nada } }')->assertOk()->assertJsonStructure(['errors' => [['message']]]);
        $this->metric(['entity' => 'EQUIPMENT', 'metric' => 'COUNT', 'groupBy' => 'category'])->assertJsonMissingPath('errors'); // alias de las plantillas
    }

    public function test_limit_applies_to_rankings(): void
    {
        $this->seedActivity();

        $points = $this->metric(['entity' => 'TEACHERS', 'metric' => 'COUNT', 'limit' => 1])->json('data.customMetric.points');

        $this->assertCount(1, $points);
    }

    public function test_query_depth_is_limited(): void
    {
        $deep = '{ customMetric(input: {entity: LOANS, metric: COUNT, timeRange: LAST_7_DAYS, chartType: BAR}) { points { label } } }';
        $this->gql($deep)->assertOk()->assertJsonMissingPath('errors');

        $abusive = '{ executiveKpis { '.str_repeat('a: activosDisponibles ', 300).' } }';
        $this->gql($abusive)->assertOk()->assertJsonStructure(['errors']);
    }

    public function test_only_director_and_admin_can_use_the_endpoint(): void
    {
        foreach ([User::ROLE_WAREHOUSE, User::ROLE_TEACHER] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role, 'is_active' => true]));
            $this->gql(self::KPIS)->assertForbidden();
        }

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]));
        $this->gql(self::KPIS)->assertOk();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/metrics', ['query' => '{ executiveKpis { activosDisponibles } }'])->assertUnauthorized();
    }

    public function test_request_without_query_is_rejected(): void
    {
        $this->postJson('/api/metrics', [])->assertUnprocessable()->assertJsonValidationErrors('query');
    }
}
