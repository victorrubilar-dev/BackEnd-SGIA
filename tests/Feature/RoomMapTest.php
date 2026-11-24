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
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * REQ-22: plano de la sala (`GET /api/locations/{id}/map`) y medidas de la sala.
 */
class RoomMapTest extends TestCase
{
    use DatabaseTransactions;

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    public function test_map_uses_default_dimensions_when_room_has_none(): void
    {
        Sanctum::actingAs($this->user(User::ROLE_TEACHER));
        $location = Location::factory()->create();

        $this->getJson("/api/locations/{$location->id}/map")
            ->assertOk()
            ->assertJsonPath('data.location.id', $location->id)
            ->assertJsonPath('data.location.code', $location->code)
            ->assertJsonPath('data.location.map_width_cm', 1000)
            ->assertJsonPath('data.location.map_height_cm', 600)
            ->assertJsonPath('data.location.has_dimensions', false)
            ->assertJsonPath('data.zonas', [])
            ->assertJsonPath('data.estantes', [])
            ->assertJsonPath('data.cajones_sin_estante_count', 0);
    }

    public function test_map_counts_supplies_and_units_without_double_counting(): void
    {
        Sanctum::actingAs($this->user(User::ROLE_ADMIN));
        $location = Location::factory()->create(['map_width_cm' => 800, 'map_height_cm' => 500]);
        $zona = Zona::factory()->create(['location_id' => $location->id, 'nombre' => 'Zona A']);
        $estante = Estante::factory()->create(['location_id' => $location->id, 'zona_id' => $zona->id, 'codigo' => 'EST-A1', 'rotation' => 90]);
        $r2 = Cajon::factory()->create(['location_id' => $location->id, 'estante_id' => $estante->id, 'codigo' => 'R2']);
        $r1 = Cajon::factory()->create(['location_id' => $location->id, 'estante_id' => $estante->id, 'codigo' => 'R1']);
        Cajon::factory()->create(['location_id' => $location->id, 'codigo' => 'SUELTO']);

        // Dos insumos activos y uno inactivo en R1.
        Product::factory()->supply()->count(2)->create(['cajon_id' => $r1->id, 'location_id' => $location->id, 'quantity' => 50]);
        Product::factory()->supply()->create(['cajon_id' => $r1->id, 'location_id' => $location->id, 'is_active' => false]);
        // Equipo con cajon_id en R2: cuenta por sus unidades (2), no por el producto; la dada de baja no cuenta.
        $equipo = Product::factory()->create(['cajon_id' => $r2->id, 'location_id' => $location->id]);
        EquipmentUnit::factory()->available()->create(['product_id' => $equipo->id, 'cajon_id' => $r2->id]);
        EquipmentUnit::factory()->onLoan()->create(['product_id' => $equipo->id, 'cajon_id' => $r2->id]);
        EquipmentUnit::factory()->writtenOff()->create(['product_id' => $equipo->id, 'cajon_id' => $r2->id]);

        $response = $this->getJson("/api/locations/{$location->id}/map")->assertOk();

        $response->assertJsonPath('data.location.has_dimensions', true)
            ->assertJsonPath('data.location.map_width_cm', 800)
            ->assertJsonPath('data.zonas.0.nombre', 'Zona A')
            ->assertJsonPath('data.estantes.0.codigo', 'EST-A1')
            ->assertJsonPath('data.estantes.0.zona_id', $zona->id)
            ->assertJsonPath('data.estantes.0.rotation', 90)
            ->assertJsonPath('data.estantes.0.cajones.0.codigo', 'R1')
            ->assertJsonPath('data.estantes.0.cajones.0.products_count', 2)
            ->assertJsonPath('data.estantes.0.cajones.0.units_count', 0)
            ->assertJsonPath('data.estantes.0.cajones.1.codigo', 'R2')
            ->assertJsonPath('data.estantes.0.cajones.1.products_count', 0)
            ->assertJsonPath('data.estantes.0.cajones.1.units_count', 2)
            ->assertJsonPath('data.estantes.0.items_count', 4)
            ->assertJsonPath('data.estantes.0.has_critical_stock', false)
            ->assertJsonPath('data.cajones_sin_estante_count', 1);
    }

    public function test_map_flags_critical_stock(): void
    {
        Sanctum::actingAs($this->user(User::ROLE_WAREHOUSE));
        $location = Location::factory()->create();
        $estante = Estante::factory()->create(['location_id' => $location->id]);
        $cajon = Cajon::factory()->create(['location_id' => $location->id, 'estante_id' => $estante->id]);
        Product::factory()->supply()->create(['cajon_id' => $cajon->id, 'quantity' => 5, 'stock_minimo' => 5]);

        $this->getJson("/api/locations/{$location->id}/map")
            ->assertOk()
            ->assertJsonPath('data.estantes.0.has_critical_stock', true);
    }

    public function test_map_and_contents_report_full_cajones(): void
    {
        Sanctum::actingAs($this->user(User::ROLE_ADMIN));
        $location = Location::factory()->create();
        $estante = Estante::factory()->create(['location_id' => $location->id]);
        Cajon::factory()->create(['location_id' => $location->id, 'estante_id' => $estante->id, 'codigo' => 'A', 'lleno' => true]);
        Cajon::factory()->create(['location_id' => $location->id, 'estante_id' => $estante->id, 'codigo' => 'B']);

        $this->getJson("/api/locations/{$location->id}/map")
            ->assertOk()
            ->assertJsonPath('data.estantes.0.cajones.0.lleno', true)
            ->assertJsonPath('data.estantes.0.cajones.1.lleno', false);

        $this->getJson("/api/estantes/{$estante->id}/contents")
            ->assertOk()
            ->assertJsonPath('data.cajones.0.lleno', true)
            ->assertJsonPath('data.cajones.1.lleno', false)
            ->assertJsonPath('data.estante.cajones.0.lleno', true);
    }

    public function test_map_query_count_does_not_grow_with_estantes(): void
    {
        Sanctum::actingAs($this->user(User::ROLE_DIRECTOR));
        $location = Location::factory()->create();
        foreach (range(0, 9) as $i) {
            $estante = Estante::factory()->create(['location_id' => $location->id, 'x' => $i * 100]);
            Cajon::factory()->count(2)->create(['location_id' => $location->id, 'estante_id' => $estante->id]);
        }

        DB::enableQueryLog();
        $this->getJson("/api/locations/{$location->id}/map")->assertOk()->assertJsonCount(10, 'data.estantes');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(10, $queries, "El plano hizo {$queries} consultas.");
    }

    public function test_map_of_missing_room_is_404(): void
    {
        Sanctum::actingAs($this->user(User::ROLE_WAREHOUSE));

        $this->getJson('/api/locations/999999/map')->assertNotFound();
    }

    public function test_room_dimensions_are_saved_together(): void
    {
        Sanctum::actingAs($this->user(User::ROLE_WAREHOUSE));
        $location = Location::factory()->create();

        $this->patchJson("/api/locations/{$location->id}", ['map_width_cm' => 1200, 'map_height_cm' => 700])
            ->assertOk()
            ->assertJsonPath('data.map_width_cm', 1200)
            ->assertJsonPath('data.map_height_cm', 700);

        $this->patchJson("/api/locations/{$location->id}", ['map_width_cm' => 1200])
            ->assertUnprocessable()->assertJsonValidationErrors('map_height_cm');
        $this->patchJson("/api/locations/{$location->id}", ['map_width_cm' => 50, 'map_height_cm' => 700])
            ->assertUnprocessable()->assertJsonValidationErrors('map_width_cm');
    }

    public function test_shrinking_room_with_estante_outside_is_rejected(): void
    {
        Sanctum::actingAs($this->user(User::ROLE_DIRECTOR));
        $location = Location::factory()->create(['map_width_cm' => 1000, 'map_height_cm' => 600]);
        Estante::factory()->create(['location_id' => $location->id, 'codigo' => 'EST-B1', 'x' => 800, 'y' => 0, 'width' => 150]);

        $this->patchJson("/api/locations/{$location->id}", ['map_width_cm' => 900, 'map_height_cm' => 600])
            ->assertUnprocessable()
            ->assertJsonPath('errors.map_width_cm.0', 'El estante EST-B1 quedaría fuera de la sala.');

        $this->patchJson("/api/locations/{$location->id}", ['map_width_cm' => 950, 'map_height_cm' => 600])->assertOk();
    }

    public function test_teacher_cannot_change_room_dimensions(): void
    {
        Sanctum::actingAs($this->user(User::ROLE_TEACHER));
        $location = Location::factory()->create();

        $this->patchJson("/api/locations/{$location->id}", ['map_width_cm' => 900, 'map_height_cm' => 600])->assertForbidden();
    }
}
