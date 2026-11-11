<?php

namespace Tests\Feature;

use App\Enums\SupplierArea;
use App\Models\Supplier;
use App\Rules\ChileanRut;
use Database\Seeders\DemoSupplierSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Proveedores de demostración: todos con área y al menos 3 activos por área (flujo de solicitud de compra).
 */
class DemoSupplierSeederTest extends TestCase
{
    use DatabaseTransactions;

    public function test_every_demo_supplier_has_areas_and_each_area_has_three_active(): void
    {
        $this->seed(DemoSupplierSeeder::class);
        $this->seed(DemoSupplierSeeder::class); // idempotente

        $ruts = collect(DemoSupplierSeeder::SUPPLIERS)->map(fn ($d) => ChileanRut::normalize($d[1]))->values();
        $suppliers = Supplier::whereIn('rut', $ruts)->get();

        $this->assertCount(count(DemoSupplierSeeder::SUPPLIERS), $suppliers);
        $this->assertTrue($suppliers->every(fn (Supplier $s) => $s->areas->isNotEmpty()));

        foreach (SupplierArea::values() as $area) {
            $active = $suppliers->filter(fn (Supplier $s) => $s->is_active && in_array($area, $s->areaValues(), true))->count();
            $this->assertGreaterThanOrEqual(3, $active, "Área {$area}");
            $this->assertGreaterThanOrEqual(3, DemoSupplierSeeder::activeCountByArea()[$area]);
        }
    }
}
