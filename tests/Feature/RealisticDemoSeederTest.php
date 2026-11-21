<?php

namespace Tests\Feature;

use App\Enums\SupplierArea;
use App\Models\Supplier;
use App\Models\User;
use App\Rules\ChileanRut;
use Database\Seeders\RealisticDemoSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RealisticDemoSeederTest extends TestCase
{
    use DatabaseTransactions;

    /** Los datos de presentación deben poder leerse por la API (antes `/novedades` daba 500 por `problems` mal formados). */
    public function test_seeded_incident_reports_are_listed(): void
    {
        Notification::fake();
        $this->seed();
        $this->seed(RealisticDemoSeeder::class);

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_DIRECTOR, 'is_active' => true]));

        $this->getJson('/api/incident-reports?per_page=100')
            ->assertOk()
            ->assertJsonStructure(['data' => [['problems' => [['type', 'description', 'label']]]]]);
    }

    /** Proveedores de demostración: todos con área y al menos 3 activos por área (flujo de solicitud de compra). */
    public function test_every_demo_supplier_has_areas_and_each_area_has_three_active(): void
    {
        RealisticDemoSeeder::suppliers();
        RealisticDemoSeeder::suppliers(); // idempotente

        $ruts = collect(RealisticDemoSeeder::SUPPLIERS)->map(fn ($d) => ChileanRut::normalize($d[1]))->values();
        $suppliers = Supplier::whereIn('rut', $ruts)->get();

        $this->assertCount(count(RealisticDemoSeeder::SUPPLIERS), $suppliers);
        $this->assertTrue($suppliers->every(fn (Supplier $s) => $s->areas->isNotEmpty()));

        foreach (SupplierArea::values() as $area) {
            $active = $suppliers->filter(fn (Supplier $s) => $s->is_active && in_array($area, $s->areaValues(), true))->count();
            $this->assertGreaterThanOrEqual(3, $active, "Área {$area}");
            $this->assertGreaterThanOrEqual(3, RealisticDemoSeeder::activeCountByArea()[$area]);
        }
    }
}
