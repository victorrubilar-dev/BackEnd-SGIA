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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * REQ-22: estantes del plano (`/api/estantes`), límites, superposición, primer hueco y contenido.
 */
class EstanteCrudTest extends TestCase
{
    use DatabaseTransactions;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->location = Location::factory()->create(['map_width_cm' => 1000, 'map_height_cm' => 600]);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        Sanctum::actingAs($user);

        return $user;
    }

    /** @param  array<string, mixed>  $attributes */
    private function payload(array $attributes = []): array
    {
        return ['location_id' => $this->location->id, 'codigo' => 'est a1', 'width' => 200, 'depth' => 60, ...$attributes];
    }

    public function test_warehouse_creates_estante_with_position_and_audit(): void
    {
        $user = $this->actingAsRole(User::ROLE_WAREHOUSE);

        $this->postJson('/api/estantes', $this->payload(['x' => 100, 'y' => 50, 'rotation' => 90, 'nombre' => 'Rack de redes']))
            ->assertCreated()
            ->assertJsonPath('data.codigo', 'EST-A1')
            ->assertJsonPath('data.x', 100)
            ->assertJsonPath('data.rotation', 90)
            ->assertJsonPath('data.cajones', [])
            ->assertJsonPath('data.items_count', 0)
            ->assertJsonPath('data.has_critical_stock', false);

        $this->assertDatabaseHas('estantes', ['codigo' => 'EST-A1', 'created_by' => $user->id, 'updated_by' => $user->id]);
    }

    public function test_director_without_position_gets_first_free_slot(): void
    {
        $this->actingAsRole(User::ROLE_DIRECTOR);
        Estante::factory()->create(['location_id' => $this->location->id, 'x' => 0, 'y' => 0, 'width' => 200, 'depth' => 60]);

        $this->postJson('/api/estantes', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.x', 200)
            ->assertJsonPath('data.y', 0);
    }

    public function test_first_free_slot_prefers_the_requested_zone(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $zona = Zona::factory()->create(['location_id' => $this->location->id, 'x' => 500, 'y' => 300, 'width' => 400, 'depth' => 200]);

        $this->postJson('/api/estantes', $this->payload(['zona_id' => $zona->id]))
            ->assertCreated()
            ->assertJsonPath('data.x', 500)
            ->assertJsonPath('data.y', 300)
            ->assertJsonPath('data.zona_id', $zona->id);
    }

    public function test_no_free_space_is_rejected(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        Estante::factory()->create(['location_id' => $this->location->id, 'width' => 1000, 'depth' => 600]);

        $this->postJson('/api/estantes', $this->payload())
            ->assertUnprocessable()
            ->assertJsonPath('errors.x.0', 'No hay espacio libre en la sala para un estante de esas medidas.');
    }

    public function test_estante_outside_room_is_rejected_using_rotation(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);

        // Sin rotar cabe (900 + 60 de fondo); girado ocupa 200 de alto desde y = 500 y se sale.
        $this->postJson('/api/estantes', $this->payload(['x' => 800, 'y' => 500, 'width' => 200, 'depth' => 60, 'rotation' => 90]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.x.0', 'El estante queda fuera de la sala.');

        $this->postJson('/api/estantes', $this->payload(['x' => 800, 'y' => 500, 'width' => 200, 'depth' => 60]))->assertCreated();
    }

    public function test_overlap_is_rejected_but_touching_edges_are_allowed(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        Estante::factory()->create(['location_id' => $this->location->id, 'codigo' => 'EST-B1', 'x' => 0, 'y' => 0, 'width' => 200, 'depth' => 60]);

        $this->postJson('/api/estantes', $this->payload(['x' => 150, 'y' => 30]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.x.0', 'Se superpone con el estante EST-B1.');

        $this->postJson('/api/estantes', $this->payload(['x' => 200, 'y' => 0]))->assertCreated();
    }

    public function test_code_is_unique_per_room_and_zone_must_be_of_same_room(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        Estante::factory()->create(['location_id' => $this->location->id, 'codigo' => 'EST-A1', 'x' => 900, 'y' => 500]);
        $otherZona = Zona::factory()->create();

        $this->postJson('/api/estantes', $this->payload(['x' => 0, 'y' => 0]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.codigo.0', 'Ya existe un estante con el código EST-A1 en esta sala.');
        $this->postJson('/api/estantes', $this->payload(['codigo' => 'EST-A2', 'zona_id' => $otherZona->id]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.zona_id.0', 'La zona no pertenece a la sala.');
        $this->postJson('/api/estantes', $this->payload(['codigo' => '---']))
            ->assertUnprocessable()->assertJsonValidationErrors('codigo');
        $this->postJson('/api/estantes', $this->payload(['codigo' => 'EST-A2', 'rotation' => 45, 'width' => 5]))
            ->assertUnprocessable()->assertJsonValidationErrors(['rotation', 'width']);

        // El mismo código en otra sala sí se puede.
        $this->postJson('/api/estantes', $this->payload(['location_id' => Location::factory()->create()->id]))->assertCreated();
    }

    public function test_patch_moves_rotates_and_validates_resulting_values(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $zona = Zona::factory()->create(['location_id' => $this->location->id]);
        $estante = Estante::factory()->create(['location_id' => $this->location->id, 'x' => 0, 'y' => 0, 'width' => 200, 'depth' => 60]);
        Estante::factory()->create(['location_id' => $this->location->id, 'codigo' => 'EST-C1', 'x' => 400, 'y' => 0, 'width' => 100, 'depth' => 100]);

        $this->patchJson("/api/estantes/{$estante->id}", ['x' => 300, 'y' => 200, 'zona_id' => $zona->id])
            ->assertOk()
            ->assertJsonPath('data.x', 300)
            ->assertJsonPath('data.zona_id', $zona->id);

        // Girar en su lugar: (300, 200, 60, 200) no choca. Mover junto a EST-C1 sí.
        $this->patchJson("/api/estantes/{$estante->id}", ['rotation' => 90])->assertOk()->assertJsonPath('data.rotation', 90);
        $this->patchJson("/api/estantes/{$estante->id}", ['x' => 420, 'y' => 0])
            ->assertUnprocessable()->assertJsonPath('errors.x.0', 'Se superpone con el estante EST-C1.');
        // Moverse sobre sí mismo no cuenta como superposición.
        $this->patchJson("/api/estantes/{$estante->id}", ['x' => 310])->assertOk();

        $this->patchJson("/api/estantes/{$estante->id}", ['zona_id' => null])->assertOk()->assertJsonPath('data.zona_id', null);
        $this->patchJson("/api/estantes/{$estante->id}", ['location_id' => Location::factory()->create()->id])
            ->assertUnprocessable()->assertJsonPath('errors.location_id.0', 'Un estante no se puede mover a otra sala.');
    }

    public function test_delete_is_blocked_while_it_has_cajones(): void
    {
        $this->actingAsRole(User::ROLE_DIRECTOR);
        $estante = Estante::factory()->create(['location_id' => $this->location->id]);
        $cajon = Cajon::factory()->create(['location_id' => $this->location->id, 'estante_id' => $estante->id]);

        $this->deleteJson("/api/estantes/{$estante->id}")
            ->assertUnprocessable()
            ->assertJsonPath('cajones_count', 1)
            ->assertJsonPath('errors.estante.0', 'El estante tiene 1 cajones; muévelos antes de eliminarlo.');

        $cajon->update(['estante_id' => null]);
        $this->deleteJson("/api/estantes/{$estante->id}")->assertNoContent();
        $this->assertModelMissing($estante);
    }

    public function test_admin_and_teacher_read_but_cannot_write(): void
    {
        $estante = Estante::factory()->create(['location_id' => $this->location->id, 'codigo' => 'EST-R1']);

        foreach ([User::ROLE_ADMIN, User::ROLE_TEACHER] as $role) {
            $this->actingAsRole($role);
            $this->getJson("/api/estantes?location_id={$this->location->id}")->assertOk()->assertJsonPath('data.0.codigo', 'EST-R1');
            $this->getJson("/api/estantes/{$estante->id}")->assertOk();
            $this->getJson("/api/estantes/{$estante->id}/contents")->assertOk();
            $this->postJson('/api/estantes', $this->payload(['codigo' => 'EST-X']))->assertForbidden();
            $this->patchJson("/api/estantes/{$estante->id}", ['x' => 10])->assertForbidden();
            $this->deleteJson("/api/estantes/{$estante->id}")->assertForbidden();
        }
    }

    public function test_index_filters_by_room_and_search(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        Estante::factory()->create(['location_id' => $this->location->id, 'codigo' => 'EST-A1', 'nombre' => 'Rack redes']);
        Estante::factory()->create(['location_id' => $this->location->id, 'codigo' => 'EST-B1', 'x' => 300]);
        Estante::factory()->create(['codigo' => 'EST-A1']);

        $this->getJson("/api/estantes?location_id={$this->location->id}")->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/estantes?location_id={$this->location->id}&search=redes")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.codigo', 'EST-A1');
    }

    public function test_contents_lists_supplies_and_groups_units_by_product(): void
    {
        $this->actingAsRole(User::ROLE_ADMIN);
        $zona = Zona::factory()->create(['location_id' => $this->location->id, 'nombre' => 'Zona A']);
        $estante = Estante::factory()->create(['location_id' => $this->location->id, 'zona_id' => $zona->id, 'codigo' => 'EST-A2']);
        $r1 = Cajon::factory()->create(['location_id' => $this->location->id, 'estante_id' => $estante->id, 'codigo' => 'R1']);
        Cajon::factory()->create(['location_id' => $this->location->id, 'estante_id' => $estante->id, 'codigo' => 'R2']);

        Product::factory()->supply()->create(['cajon_id' => $r1->id, 'name' => 'Guantes', 'quantity' => 3, 'stock_minimo' => 5]);
        Product::factory()->supply()->create(['cajon_id' => $r1->id, 'name' => 'Alcohol', 'quantity' => 20, 'stock_minimo' => 5]);
        Product::factory()->supply()->create(['cajon_id' => $r1->id, 'name' => 'Inactivo', 'is_active' => false]);
        $multimetro = Product::factory()->create(['name' => 'Multímetro', 'cajon_id' => $r1->id]);
        EquipmentUnit::factory()->available()->count(2)->create(['product_id' => $multimetro->id, 'cajon_id' => $r1->id]);
        EquipmentUnit::factory()->onLoan()->create(['product_id' => $multimetro->id, 'cajon_id' => $r1->id]);
        EquipmentUnit::factory()->writtenOff()->create(['product_id' => $multimetro->id, 'cajon_id' => $r1->id]);
        $maqueta = Product::factory()->scaleModel()->create(['name' => 'Maqueta puente', 'cajon_id' => $r1->id]);
        EquipmentUnit::factory()->inRepair()->create(['product_id' => $maqueta->id, 'cajon_id' => $r1->id]);

        $this->getJson("/api/estantes/{$estante->id}/contents")
            ->assertOk()
            ->assertJsonPath('data.estante.codigo', 'EST-A2')
            ->assertJsonPath('data.estante.items_count', 6)
            ->assertJsonPath('data.estante.has_critical_stock', true)
            ->assertJsonPath('data.zona.nombre', 'Zona A')
            ->assertJsonPath('data.location.code', $this->location->code)
            ->assertJsonCount(2, 'data.cajones')
            ->assertJsonPath('data.cajones.0.codigo', 'R1')
            ->assertJsonCount(2, 'data.cajones.0.productos')
            ->assertJsonPath('data.cajones.0.productos.0.name', 'Alcohol')
            ->assertJsonPath('data.cajones.0.productos.1.name', 'Guantes')
            ->assertJsonPath('data.cajones.0.productos.1.is_critical', true)
            ->assertJsonCount(2, 'data.cajones.0.unidades')
            ->assertJsonPath('data.cajones.0.unidades.0.product_name', 'Maqueta puente')
            ->assertJsonPath('data.cajones.0.unidades.0.by_status', ['disponible' => 0, 'prestado' => 0, 'en_reparacion' => 1])
            ->assertJsonPath('data.cajones.0.unidades.1.product_name', 'Multímetro')
            ->assertJsonPath('data.cajones.0.unidades.1.total', 3)
            ->assertJsonPath('data.cajones.0.unidades.1.by_status', ['disponible' => 2, 'prestado' => 1, 'en_reparacion' => 0])
            ->assertJsonPath('data.cajones.1.productos', [])
            ->assertJsonPath('data.cajones.1.unidades', []);
    }
}
