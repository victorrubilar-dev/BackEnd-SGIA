<?php

namespace Tests\Feature;

use App\Models\Estante;
use App\Models\Location;
use App\Models\User;
use App\Models\Zona;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * REQ-22: zonas del plano (`/api/zonas`).
 */
class ZonaCrudTest extends TestCase
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
        return ['location_id' => $this->location->id, 'nombre' => 'Zona A', 'x' => 50, 'y' => 50, 'width' => 400, 'depth' => 300, ...$attributes];
    }

    public function test_warehouse_creates_and_lists_zones(): void
    {
        $user = $this->actingAsRole(User::ROLE_WAREHOUSE);

        $this->postJson('/api/zonas', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.nombre', 'Zona A')
            ->assertJsonPath('data.width', 400);
        $this->assertDatabaseHas('zonas', ['nombre' => 'Zona A', 'created_by' => $user->id]);

        // Las zonas pueden superponerse.
        $this->postJson('/api/zonas', $this->payload(['nombre' => 'Zona B', 'x' => 100]))->assertCreated();
        Zona::factory()->create(['nombre' => 'Zona de otra sala']);

        $this->getJson("/api/zonas?location_id={$this->location->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.nombre', 'Zona A');
    }

    public function test_zone_validation(): void
    {
        $this->actingAsRole(User::ROLE_DIRECTOR);
        Zona::factory()->create(['location_id' => $this->location->id, 'nombre' => 'Zona A']);

        $this->postJson('/api/zonas', $this->payload(['nombre' => 'zona a']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.nombre.0', 'Ya existe una zona llamada zona a en esta sala.');
        $this->postJson('/api/zonas', $this->payload(['nombre' => 'Zona C', 'x' => 700]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.x.0', 'La zona queda fuera de la sala.');
        $this->postJson('/api/zonas', $this->payload(['nombre' => 'Zona C', 'width' => 10]))
            ->assertUnprocessable()->assertJsonValidationErrors('width');
    }

    public function test_patch_moves_and_resizes_with_resulting_values(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $zona = Zona::factory()->create(['location_id' => $this->location->id, 'x' => 0, 'y' => 0, 'width' => 300, 'depth' => 300]);

        $this->patchJson("/api/zonas/{$zona->id}", ['x' => 600, 'width' => 400])
            ->assertOk()->assertJsonPath('data.x', 600)->assertJsonPath('data.width', 400);
        $this->patchJson("/api/zonas/{$zona->id}", ['width' => 500])
            ->assertUnprocessable()->assertJsonPath('errors.x.0', 'La zona queda fuera de la sala.');
        $this->patchJson("/api/zonas/{$zona->id}", ['location_id' => Location::factory()->create()->id])
            ->assertUnprocessable()->assertJsonValidationErrors('location_id');
        // Renombrarse con su propio nombre no choca.
        $this->patchJson("/api/zonas/{$zona->id}", ['nombre' => $zona->nombre])->assertOk();
    }

    public function test_deleting_a_zone_leaves_its_estantes_without_zone(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $zona = Zona::factory()->create(['location_id' => $this->location->id]);
        $estante = Estante::factory()->create(['location_id' => $this->location->id, 'zona_id' => $zona->id]);

        $this->deleteJson("/api/zonas/{$zona->id}")->assertNoContent();

        $this->assertModelMissing($zona);
        $this->assertNull($estante->fresh()->zona_id);
    }

    public function test_admin_and_teacher_read_but_cannot_write(): void
    {
        $zona = Zona::factory()->create(['location_id' => $this->location->id]);

        foreach ([User::ROLE_ADMIN, User::ROLE_TEACHER] as $role) {
            $this->actingAsRole($role);
            $this->getJson("/api/zonas?location_id={$this->location->id}")->assertOk()->assertJsonCount(1, 'data');
            $this->postJson('/api/zonas', $this->payload(['nombre' => 'Zona X']))->assertForbidden();
            $this->patchJson("/api/zonas/{$zona->id}", ['x' => 10])->assertForbidden();
            $this->deleteJson("/api/zonas/{$zona->id}")->assertForbidden();
        }
    }
}
