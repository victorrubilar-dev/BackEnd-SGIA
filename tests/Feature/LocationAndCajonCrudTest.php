<?php

namespace Tests\Feature;

use App\Models\Cajon;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LocationAndCajonCrudTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;
    protected User $warehouse;
    protected User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'email' => 'admin_loc_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $this->warehouse = User::factory()->create([
            'email' => 'panol_loc_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_WAREHOUSE,
            'is_active' => true,
        ]);

        $this->teacher = User::factory()->create([
            'email' => 'profe_loc_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_TEACHER,
            'is_active' => true,
        ]);
    }

    public function test_can_list_and_filter_locations(): void
    {
        Sanctum::actingAs($this->teacher);

        $loc = Location::factory()->create([
            'nombre' => 'Sala Multimedia 404',
            'tipo' => Location::TIPO_SALA,
        ]);

        $response = $this->getJson('/api/locations?search=Multimedia');

        $response->assertStatus(200);
        $response->assertJsonFragment(['nombre' => 'Sala Multimedia 404']);
    }

    public function test_warehouse_can_create_location_with_audit(): void
    {
        Sanctum::actingAs($this->warehouse);

        $response = $this->postJson('/api/locations', [
            'nombre' => 'Pañol de Automatización',
            'tipo' => 'panol',
            'descripcion' => 'Sector de PLC y neumática',
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'data' => [
                'nombre' => 'Pañol de Automatización',
                'tipo' => 'panol',
                'created_by' => $this->warehouse->id,
                'updated_by' => $this->warehouse->id,
            ],
        ]);

        $this->assertDatabaseHas('locations', [
            'nombre' => 'Pañol de Automatización',
            'tipo' => 'panol',
            'created_by' => $this->warehouse->id,
        ]);
    }

    public function test_warehouse_can_create_cajon_inside_location_with_audit(): void
    {
        Sanctum::actingAs($this->warehouse);

        $location = Location::factory()->create(['nombre' => 'Pañol B']);

        $response = $this->postJson('/api/cajones', [
            'location_id' => $location->id,
            'codigo' => 'Gaveta-PLC-01',
            'descripcion' => 'Módulos Siemens S7-1200',
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'data' => [
                'codigo' => 'Gaveta-PLC-01',
                'location_id' => $location->id,
                'created_by' => $this->warehouse->id,
                'updated_by' => $this->warehouse->id,
            ],
        ]);

        $this->assertDatabaseHas('cajones', [
            'location_id' => $location->id,
            'codigo' => 'Gaveta-PLC-01',
            'created_by' => $this->warehouse->id,
        ]);
    }

    public function test_duplicate_cajon_code_in_same_location_is_rejected(): void
    {
        Sanctum::actingAs($this->warehouse);

        $location = Location::factory()->create();
        Cajon::factory()->create([
            'location_id' => $location->id,
            'codigo' => 'Cajon-X',
        ]);

        $response = $this->postJson('/api/cajones', [
            'location_id' => $location->id,
            'codigo' => 'Cajon-X',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['codigo']);
    }

    public function test_teacher_cannot_create_locations_or_cajones(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/locations', [
            'nombre' => 'Sala Hack',
        ])->assertStatus(403);

        $location = Location::factory()->create();

        $this->postJson('/api/cajones', [
            'location_id' => $location->id,
            'codigo' => 'Cajon-Hack',
        ])->assertStatus(403);
    }

    public function test_warehouse_can_update_location_and_cajon(): void
    {
        Sanctum::actingAs($this->warehouse);

        $location = Location::factory()->create(['nombre' => 'Sala Vieja']);
        $cajon = Cajon::factory()->create(['location_id' => $location->id, 'codigo' => 'Cajon-Viejo']);

        $resLoc = $this->patchJson('/api/locations/' . $location->id, [
            'nombre' => 'Sala Renovada',
        ]);
        $resLoc->assertStatus(200);
        $this->assertEquals('Sala Renovada', $location->fresh()->nombre);
        $this->assertEquals($this->warehouse->id, $location->fresh()->updated_by);

        $resCajon = $this->patchJson('/api/cajones/' . $cajon->id, [
            'codigo' => 'Cajon-Renovado',
        ]);
        $resCajon->assertStatus(200);
        $this->assertEquals('Cajon-Renovado', $cajon->fresh()->codigo);
        $this->assertEquals($this->warehouse->id, $cajon->fresh()->updated_by);
    }
}
