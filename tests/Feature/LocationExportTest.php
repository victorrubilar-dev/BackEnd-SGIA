<?php

namespace Tests\Feature;

use App\Models\Cajon;
use App\Models\EquipmentUnit;
use App\Models\Estante;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use App\Models\Zona;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ReadsXlsx;
use Tests\TestCase;

/**
 * Reporte Excel de ubicaciones `GET /api/locations/export` (docs/reportes-excel.md, ampliación A1).
 */
class LocationExportTest extends TestCase
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

    public function test_exports_where_each_thing_is_in_one_room(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $room = Location::factory()->create(['tipo' => Location::TIPO_SALA]);
        $zona = Zona::factory()->create(['location_id' => $room->id, 'nombre' => 'Zona norte']);
        $estante = Estante::factory()->create(['location_id' => $room->id, 'zona_id' => $zona->id, 'codigo' => 'RACK', 'nombre' => 'Rack de redes']);
        $cajon = Cajon::factory()->create(['location_id' => $room->id, 'estante_id' => $estante->id, 'codigo' => 'R1', 'lleno' => true]);
        $router = Product::factory()->create(['name' => 'Router de prueba', 'type' => Product::TYPE_EQUIPMENT]);
        $unit = EquipmentUnit::factory()->create(['product_id' => $router->id, 'cajon_id' => $cajon->id, 'serial_number' => 'SN-R1']);
        Product::factory()->create(['name' => 'Cable en cajón', 'type' => Product::TYPE_SUPPLY, 'cajon_id' => $cajon->id, 'quantity' => 7, 'stock_minimo' => 2]);
        Product::factory()->create(['name' => 'Cinta en la sala', 'type' => Product::TYPE_SUPPLY, 'cajon_id' => null, 'location_id' => $room->id, 'quantity' => 1, 'stock_minimo' => 5]);
        $elsewhere = EquipmentUnit::factory()->create(['product_id' => $router->id]);

        $response = $this->get("/api/locations/export?location_id={$room->id}")->assertOk();
        $this->assertMatchesRegularExpression('/filename="ubicaciones-\d{8}-\d{6}\.xlsx"/', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame(['Dónde está cada cosa', 'Cajones', 'Salas', 'Info'], array_keys($this->readXlsx($response)));

        $items = collect($this->xlsxRecords($response, 'Dónde está cada cosa'))->keyBy('Producto');
        $this->assertSame(['Cable en cajón', 'Router de prueba', 'Cinta en la sala'], $items->keys()->all(), 'por cajón; lo que está suelto en la sala sin cajón');
        $router = $items['Router de prueba'];
        $this->assertSame([$unit->code, 'SN-R1', 'Disponible', 1], [$router['Código'], $router['N° de serie'], $router['Estado'], $router['Cantidad']]);
        $this->assertSame(['Zona norte', 'RACK Rack de redes', 'R1', $cajon->fresh()->barcode, 'sí'], [$router['Zona'], $router['Estante'], $router['Cajón'], $router['Código del cajón'], $router['Cajón lleno']]);
        $this->assertSame('Sala', $router['Tipo de sala']);
        $this->assertSame(7, $items['Cable en cajón']['Cantidad']);
        $this->assertSame('Stock crítico', $items['Cinta en la sala']['Estado']);
        $this->assertArrayNotHasKey('Cajón', $items['Cinta en la sala']);
        $this->assertNotContains($elsewhere->code, $items->pluck('Código')->all());

        $cajones = $this->xlsxRecords($response, 'Cajones');
        $this->assertCount(1, $cajones);
        $this->assertSame([1, 1, 'sí'], [$cajones[0]['Insumos'], $cajones[0]['Unidades'], $cajones[0]['Lleno']]);

        $salas = $this->xlsxRecords($response, 'Salas');
        $this->assertCount(1, $salas);
        $this->assertSame([1, 1, 1, 2, 1], [$salas[0]['Estantes'], $salas[0]['Cajones'], $salas[0]['Cajones llenos'], $salas[0]['Insumos'], $salas[0]['Unidades']]);
        $this->assertStringStartsWith('Sala: ', $this->xlsxInfo($response)['Filtros']);
    }

    public function test_full_report_lists_unlocated_things_last(): void
    {
        $this->actingAsRole(User::ROLE_ADMIN);
        $loose = Product::factory()->create(['type' => Product::TYPE_SUPPLY, 'cajon_id' => null, 'location_id' => null]);

        $items = $this->xlsxRecords($this->get('/api/locations/export')->assertOk(), 'Dónde está cada cosa');
        $unlocated = collect($items)->firstWhere('Código', $loose->barcode);
        $this->assertSame('Sin ubicación', $unlocated['Sala']);
        $this->assertSame('Sin ubicación', end($items)['Sala'], 'lo que no tiene ubicación va al final');
    }

    public function test_roles_and_validation(): void
    {
        $this->actingAsRole(User::ROLE_TEACHER);
        $this->getJson('/api/locations/export')->assertForbidden();

        $this->actingAsRole(User::ROLE_DIRECTOR);
        $this->getJson('/api/locations/export?tipo=cocina')->assertUnprocessable()->assertJsonValidationErrors('tipo');
        $this->getJson('/api/locations/export?location_id=abc')->assertUnprocessable()->assertJsonValidationErrors('location_id');
    }
}
