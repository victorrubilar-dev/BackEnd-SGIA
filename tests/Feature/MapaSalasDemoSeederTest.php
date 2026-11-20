<?php

namespace Tests\Feature;

use App\Models\Cajon;
use App\Models\Estante;
use App\Models\Location;
use App\Models\Zona;
use App\Services\RoomMapService;
use Database\Seeders\MapaSalasDemoSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * REQ-22 fase 6: datos de prueba del mapa de salas (`docs/mapa-salas.md` § Datos de prueba).
 */
class MapaSalasDemoSeederTest extends TestCase
{
    use DatabaseTransactions;

    private function seeder(): MapaSalasDemoSeeder
    {
        return app(MapaSalasDemoSeeder::class);
    }

    private function assertValidLayout(Location $location): void
    {
        $map = app(RoomMapService::class);
        $room = $map->roomRect($location->fresh());
        $estantes = Estante::where('location_id', $location->id)->get();

        foreach ($estantes as $estante) {
            $rect = $map->estanteRect($estante);
            $this->assertTrue($map->contains($room, $rect), "{$estante->codigo} queda fuera de la sala");
            $this->assertNull($map->overlappingEstante($location->id, $rect, $estante->id), "{$estante->codigo} se superpone");
        }
        foreach (Zona::where('location_id', $location->id)->get() as $zona) {
            $this->assertTrue($map->contains($room, $map->zonaRect($zona)), "{$zona->nombre} queda fuera de la sala");
        }
    }

    public function test_empty_room_gets_zones_shelves_and_assigned_drawers(): void
    {
        $location = Location::factory()->create(['tipo' => Location::TIPO_SALA]);

        $this->seeder()->seedLocation($location);

        $location->refresh();
        $this->assertSame([1000, 600], [$location->map_width_cm, $location->map_height_cm]);
        $this->assertSame(['Zona A', 'Zona B', 'Zona C'], Zona::where('location_id', $location->id)->orderBy('nombre')->pluck('nombre')->all());
        $estantes = Estante::where('location_id', $location->id)->orderBy('codigo')->get();
        $this->assertSame(['EST-A1', 'EST-A2', 'EST-A3', 'EST-B1', 'EST-B2', 'EST-C1', 'EST-C2'], $estantes->pluck('codigo')->all());
        $this->assertTrue($estantes->where('zona_id', null)->isEmpty());
        $this->assertSame([90], $estantes->filter(fn (Estante $e) => str_starts_with($e->codigo, 'EST-A'))->pluck('rotation')->unique()->values()->all());

        $cajones = Cajon::where('location_id', $location->id)->get();
        $this->assertCount(14, $cajones);
        $this->assertTrue($cajones->every(fn (Cajon $c) => $c->estante_id !== null));
        $this->assertDatabaseHas('cajones', ['location_id' => $location->id, 'codigo' => 'A1-1', 'barcode' => $location->code.'-A1-1']);
        $this->assertValidLayout($location);
    }

    public function test_room_with_drawers_and_own_shelf_is_respected(): void
    {
        $location = Location::factory()->create(['tipo' => Location::TIPO_PANOL, 'map_width_cm' => 1200, 'map_height_cm' => 800]);
        // Estante propio justo donde el plan pone EST-A1: el seeder debe buscar otro lugar.
        $propio = Estante::factory()->create(['location_id' => $location->id, 'codigo' => 'EST-1', 'x' => 150, 'y' => 200, 'width' => 200, 'depth' => 60, 'rotation' => 90]);
        $asignado = Cajon::factory()->create(['location_id' => $location->id, 'codigo' => 'YA', 'estante_id' => $propio->id]);
        foreach (['G1', 'G2', 'G3'] as $codigo) {
            Cajon::factory()->create(['location_id' => $location->id, 'codigo' => $codigo]);
        }

        $this->seeder()->seedLocation($location);

        $this->assertSame([1200, 800], [$location->fresh()->map_width_cm, $location->fresh()->map_height_cm]);
        $this->assertSame(['x' => 150, 'y' => 200], $propio->fresh()->only(['x', 'y']));
        $this->assertSame(8, Estante::where('location_id', $location->id)->count());
        $this->assertSame(4, Cajon::where('location_id', $location->id)->count(), 'No crea cajones si la sala ya tenía');
        $this->assertSame(0, Cajon::where('location_id', $location->id)->whereNull('estante_id')->count());
        $this->assertSame($propio->id, $asignado->fresh()->estante_id, 'Un cajón con estante no se mueve');
        $this->assertValidLayout($location);
    }

    public function test_running_twice_creates_nothing_new(): void
    {
        $location = Location::factory()->create(['tipo' => Location::TIPO_BODEGA]);
        $this->seeder()->seedLocation($location);
        $counts = [Zona::count(), Estante::count(), Cajon::count()];

        $this->seeder()->seedLocation($location->fresh());

        $this->assertSame($counts, [Zona::count(), Estante::count(), Cajon::count()]);
        $this->assertSame([1600, 1000], [$location->fresh()->map_width_cm, $location->fresh()->map_height_cm]);
        $this->assertValidLayout($location);
    }

    public function test_every_location_type_gets_a_valid_layout(): void
    {
        foreach (Location::TIPOS as $tipo) {
            $location = Location::factory()->create(['tipo' => $tipo]);
            $this->seeder()->seedLocation($location);
            $this->assertSame(7, Estante::where('location_id', $location->id)->count(), $tipo);
            $this->assertValidLayout($location);
        }
    }
}
