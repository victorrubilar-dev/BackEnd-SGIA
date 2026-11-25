<?php

namespace Tests\Feature;

use App\Models\Cajon;
use App\Models\EquipmentUnit;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use App\Reports\ReportExporter;
use App\Support\XlsxWriter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ReadsXlsx;
use Tests\TestCase;

/**
 * Reporte Excel del inventario `GET /api/products/export` (docs/reportes-excel.md).
 */
class ProductExportTest extends TestCase
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

    public function test_exports_products_units_and_info_with_the_list_filters(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $tag = 'RPT'.fake()->unique()->numerify('####');
        $location = Location::factory()->create();
        $cajon = Cajon::factory()->create(['location_id' => $location->id]);
        $supply = Product::factory()->create(['name' => "{$tag} Cable UTP", 'type' => Product::TYPE_SUPPLY, 'quantity' => 3, 'stock_minimo' => 5, 'cajon_id' => $cajon->id]);
        $equipment = Product::factory()->create(['name' => "{$tag} Router", 'type' => Product::TYPE_EQUIPMENT]);
        $unit = EquipmentUnit::factory()->create(['product_id' => $equipment->id, 'serial_number' => 'SN-'.$tag]);
        $other = Product::factory()->create(['name' => 'Otro producto sin etiqueta', 'type' => Product::TYPE_SUPPLY]);

        $response = $this->get("/api/products/export?search={$tag}")->assertOk();
        $this->assertSame(XlsxWriter::MIME, $response->headers->get('Content-Type'));
        $this->assertMatchesRegularExpression('/filename="inventario-\d{8}-\d{6}\.xlsx"/', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('0', $response->headers->get(ReportExporter::TRUNCATED_HEADER));

        $this->assertSame(['Productos', 'Unidades', 'Info'], array_keys($this->readXlsx($response)));

        $products = collect($this->xlsxRecords($response, 'Productos'))->keyBy('Nombre');
        $this->assertSame(["{$tag} Cable UTP", "{$tag} Router"], $products->keys()->all());
        $this->assertSame('Insumo', $products["{$tag} Cable UTP"]['Tipo']);
        $this->assertSame(3, $products["{$tag} Cable UTP"]['Stock']);
        $this->assertSame('sí', $products["{$tag} Cable UTP"]['Stock crítico']);
        $this->assertSame($cajon->codigo, $products["{$tag} Cable UTP"]['Cajón']);
        $this->assertFalse($products->has($other->name));

        $units = $this->xlsxRecords($response, 'Unidades');
        $this->assertCount(1, $units);
        $this->assertSame($unit->code, $units[0]['Código']);
        $this->assertSame('SN-'.$tag, $units[0]['N° de serie']);

        $info = $this->xlsxInfo($response);
        $this->assertSame('Inventario', $info['Reporte']);
        $this->assertSame("Búsqueda: {$tag}", $info['Filtros']);
        $this->assertSame(2, $info['Filas en «Productos»']);
        $this->assertIsFloat($info['Fecha'] + 0.0, 'fecha como número de Excel');
    }

    public function test_without_filters_is_the_full_report(): void
    {
        $this->actingAsRole(User::ROLE_DIRECTOR);
        Product::factory()->create();

        $response = $this->get('/api/products/export')->assertOk();
        $this->assertSame('Ninguno: reporte completo', $this->xlsxInfo($response)['Filtros']);
        $this->assertSame(Product::count(), count($this->xlsxRecords($response, 'Productos')));
    }

    public function test_type_and_critical_filters_and_truncation(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $tag = 'TRN'.fake()->unique()->numerify('####');
        Product::factory()->count(3)->create(['name' => "{$tag} insumo", 'type' => Product::TYPE_SUPPLY, 'quantity' => 1, 'stock_minimo' => 5]);
        Product::factory()->create(['name' => "{$tag} equipo", 'type' => Product::TYPE_EQUIPMENT]);
        $this->app->instance(ReportExporter::class, new ReportExporter(2));

        $response = $this->get("/api/products/export?search={$tag}&type=insumo&critical_only=1")->assertOk();
        $this->assertSame('1', $response->headers->get(ReportExporter::TRUNCATED_HEADER));
        $this->assertCount(2, $this->xlsxRecords($response, 'Productos'));
        $info = $this->xlsxInfo($response);
        $this->assertStringContainsString('Tipo: Insumo', $info['Filtros']);
        $this->assertStringContainsString('Solo stock crítico: sí', $info['Filtros']);
        $this->assertStringContainsString('«Productos» se cortó', $info['Aviso']);
    }

    public function test_roles_and_validation(): void
    {
        foreach ([User::ROLE_ADMIN, User::ROLE_TEACHER] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/products/export')->assertForbidden();
        }

        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $this->getJson('/api/products/export?type=avion')->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->getJson('/api/products/export?cajon_id=abc')->assertUnprocessable()->assertJsonValidationErrors('cajon_id');
    }
}
