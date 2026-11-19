<?php

namespace Tests\Feature;

use App\Http\Resources\CajonResource;
use App\Models\Cajon;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Códigos de sala y cajón (`docs/codigos-ubicacion.md`, Fase 1).
 */
class LocationCodeTest extends TestCase
{
    use DatabaseTransactions;

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_base_code_follows_the_suggestion_rule(): void
    {
        $this->assertSame('L206', Location::baseCode('Laboratorio de Ciberseguridad L-206'));
        $this->assertSame('E301', Location::baseCode('Laboratorio E 301'));
        $this->assertSame('T102', Location::baseCode('Taller T102'));
        $this->assertSame('PCENTRAL', Location::baseCode('Pañol Central'));
        $this->assertSame('PREDES', Location::baseCode('Pañol de Redes'));
        $this->assertSame('BEDIFICIOE', Location::baseCode('Bodega Edificio E'));
        $this->assertSame('BIBLIOTE', Location::baseCode('Biblioteca'));
    }

    public function test_store_suggests_code_from_name(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);

        $this->postJson('/api/locations', ['nombre' => 'Laboratorio de Ciberseguridad L-206'])
            ->assertCreated()->assertJsonPath('data.code', 'L206');
        $this->postJson('/api/locations', ['nombre' => 'Pañol Central'])
            ->assertCreated()->assertJsonPath('data.code', 'PCENTRAL');
        $this->postJson('/api/locations', ['nombre' => 'Pañol de Redes'])
            ->assertCreated()->assertJsonPath('data.code', 'PREDES');
    }

    public function test_suggested_code_gets_numeric_suffix_when_taken(): void
    {
        $this->actingAsRole(User::ROLE_DIRECTOR);

        $this->postJson('/api/locations', ['nombre' => 'Pañol de Redes'])->assertCreated()->assertJsonPath('data.code', 'PREDES');
        $this->postJson('/api/locations', ['nombre' => 'Pañol de Redes'])->assertCreated()->assertJsonPath('data.code', 'PREDES2');
        $this->postJson('/api/locations', ['nombre' => 'Pañol de Redes'])->assertCreated()->assertJsonPath('data.code', 'PREDES3');

        Location::factory()->create(['code' => 'BEDIFICIOE']);
        $this->assertSame('BEDIFICIO2', Location::suggestCode('Bodega Edificio E'));
    }

    public function test_store_accepts_explicit_code_uppercased(): void
    {
        $this->actingAsRole(User::ROLE_DIRECTOR);

        $this->postJson('/api/locations', ['nombre' => 'Bodega Edificio E', 'code' => 'bodegae'])
            ->assertCreated()->assertJsonPath('data.code', 'BODEGAE');

        $this->postJson('/api/locations', ['nombre' => 'Otra bodega', 'code' => 'BODEGAE'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_store_rejects_invalid_code(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);

        foreach (['L-206', 'A', 'CODIGOMUYLARGO', 'Sala 1'] as $code) {
            $this->postJson('/api/locations', ['nombre' => 'Sala X', 'code' => $code])
                ->assertStatus(422)->assertJsonValidationErrors('code');
        }
    }

    public function test_location_code_cannot_be_changed(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $location = Location::factory()->create(['code' => 'L206']);

        $this->patchJson("/api/locations/{$location->id}", ['code' => 'L207'])
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'El código de la sala no se puede cambiar.');

        $this->patchJson("/api/locations/{$location->id}", ['code' => 'l206', 'nombre' => 'Lab L-206'])
            ->assertOk()
            ->assertJsonPath('data.code', 'L206')
            ->assertJsonPath('data.nombre', 'Lab L-206');

        $this->assertSame('L206', $location->fresh()->code);
    }

    public function test_cajon_code_is_location_code_plus_normalized_codigo(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $location = Location::factory()->create(['code' => 'E301']);

        $this->postJson('/api/cajones', ['location_id' => $location->id, 'codigo' => ' Mesa 1 Cajón '])
            ->assertCreated()
            ->assertJsonPath('data.barcode', 'E301-MESA-1-CAJON')
            ->assertJsonPath('data.location.code', 'E301');

        $this->assertSame('RACK-R1', Cajon::normalizeCode('rack__r1'));
        $this->assertSame('ESTANTE-2', Cajon::normalizeCode('--Estante  #2--'));
    }

    public function test_cajon_codigo_longer_than_12_normalized_chars_is_rejected(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $location = Location::factory()->create(['code' => 'L206']);

        $this->postJson('/api/cajones', ['location_id' => $location->id, 'codigo' => 'Estante grande'])
            ->assertStatus(422)->assertJsonValidationErrors('codigo');
        $this->postJson('/api/cajones', ['location_id' => $location->id, 'codigo' => '---'])
            ->assertStatus(422)->assertJsonValidationErrors('codigo');

        $this->postJson('/api/cajones', ['location_id' => $location->id, 'codigo' => 'Estante gran'])
            ->assertCreated()->assertJsonPath('data.barcode', 'L206-ESTANTE-GRAN');

        $cajon = Cajon::factory()->create(['location_id' => $location->id, 'codigo' => 'Rack-1']);
        $this->patchJson("/api/cajones/{$cajon->id}", ['codigo' => 'Rack superior 1'])
            ->assertStatus(422)->assertJsonValidationErrors('codigo');
    }

    public function test_cajon_code_cannot_collide_in_same_location(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $location = Location::factory()->create(['code' => 'PREDES']);
        $other = Location::factory()->create(['code' => 'L204']);
        Cajon::factory()->create(['location_id' => $location->id, 'codigo' => 'Rack R1']);

        $this->postJson('/api/cajones', ['location_id' => $location->id, 'codigo' => 'rack-r1'])
            ->assertStatus(422)
            ->assertJsonPath('errors.codigo.0', 'Ya existe un cajón con el código RACK-R1 en esta sala.');

        $this->postJson('/api/cajones', ['location_id' => $other->id, 'codigo' => 'rack-r1'])
            ->assertCreated()->assertJsonPath('data.barcode', 'L204-RACK-R1');

        $cajon = Cajon::factory()->create(['location_id' => $location->id, 'codigo' => 'Rack R2']);
        $this->patchJson("/api/cajones/{$cajon->id}", ['codigo' => 'RACK R1'])
            ->assertStatus(422)->assertJsonValidationErrors('codigo');

        // Mover a otra sala donde ya existe el mismo código también choca.
        $this->patchJson("/api/cajones/{$cajon->id}", ['location_id' => $other->id, 'codigo' => 'Rack R1'])
            ->assertStatus(422)->assertJsonValidationErrors('codigo');

        // Guardar el mismo código (otra escritura) no choca consigo mismo.
        $this->patchJson("/api/cajones/{$cajon->id}", ['codigo' => 'rack-r2'])
            ->assertOk()->assertJsonPath('data.barcode', 'PREDES-RACK-R2');
    }

    public function test_cajon_code_is_recomputed_when_codigo_or_location_changes(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $location = Location::factory()->create(['code' => 'PREDES']);
        $other = Location::factory()->create(['code' => 'L206']);
        $cajon = Cajon::factory()->create(['location_id' => $location->id, 'codigo' => 'Rack-R1']);
        $this->assertSame('PREDES-RACK-R1', $cajon->barcode);

        $this->patchJson("/api/cajones/{$cajon->id}", ['codigo' => 'Estante S1'])
            ->assertOk()->assertJsonPath('data.barcode', 'PREDES-ESTANTE-S1');

        $this->patchJson("/api/cajones/{$cajon->id}", ['location_id' => $other->id])
            ->assertOk()->assertJsonPath('data.barcode', 'L206-ESTANTE-S1')
            ->assertJsonPath('data.location.code', 'L206');

        $this->patchJson("/api/cajones/{$cajon->id}", ['descripcion' => 'Sin cambio de código'])
            ->assertOk()->assertJsonPath('data.barcode', 'L206-ESTANTE-S1');
    }

    public function test_find_cajon_by_code(): void
    {
        $location = Location::factory()->create(['code' => 'L206', 'nombre' => 'Lab L-206']);
        $cajon = Cajon::factory()->create(['location_id' => $location->id, 'codigo' => 'Rack R1']);

        foreach ([User::ROLE_WAREHOUSE, User::ROLE_DIRECTOR] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/cajones/by-code/l206-rack-r1')
                ->assertOk()
                ->assertJsonPath('data.id', $cajon->id)
                ->assertJsonPath('data.barcode', 'L206-RACK-R1')
                ->assertJsonPath('data.location.id', $location->id)
                ->assertJsonPath('data.location.code', 'L206')
                ->assertJsonPath('data.location.nombre', 'Lab L-206')
                ->assertJsonPath('data.location.storeroom_id', $location->storeroom_id);
        }

        $this->getJson('/api/cajones/by-code/L206-RACK-R9')
            ->assertNotFound()
            ->assertJsonPath('message', 'No existe un cajón con el código L206-RACK-R9.');

        $this->actingAsRole(User::ROLE_TEACHER);
        $this->getJson('/api/cajones/by-code/L206-RACK-R1')->assertForbidden();
    }

    public function test_cajon_label_returns_pdf(): void
    {
        $location = Location::factory()->create(['code' => 'PCENTRAL']);
        $cajon = Cajon::factory()->create(['location_id' => $location->id, 'codigo' => 'Gaveta 01']);

        foreach ([User::ROLE_WAREHOUSE, User::ROLE_DIRECTOR] as $role) {
            $this->actingAsRole($role);
            $response = $this->get("/api/cajones/{$cajon->id}/label");
            $response->assertOk();
            $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
            $this->assertStringStartsWith('%PDF', (string) $response->getContent());
            $this->assertStringContainsString('etiqueta-PCENTRAL-GAVETA-01.pdf', (string) $response->headers->get('Content-Disposition'));
        }

        $this->actingAsRole(User::ROLE_TEACHER);
        $this->getJson("/api/cajones/{$cajon->id}/label")->assertForbidden();
    }

    public function test_cajon_resource_includes_location_code_when_loaded(): void
    {
        $cajon = Cajon::factory()->create()->load('location');
        $data = (new CajonResource($cajon))->resolve(request());

        $this->assertSame($cajon->location->code, $data['location']['code']);
        $this->assertSame($cajon->location->code.'-'.Cajon::normalizeCode($cajon->codigo), $data['barcode']);
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_11_20_000000_add_code_to_locations_and_recompute_cajon_barcodes.php');
    }

    public function test_migration_uses_approved_codes_then_suggestion_rule(): void
    {
        $rows = [
            (object) ['id' => 1, 'nombre' => 'Bodega Edificio E', 'sala' => null],
            (object) ['id' => 2, 'nombre' => 'Pañol Central', 'sala' => null],
            (object) ['id' => 3, 'nombre' => 'Laboratorio de Programación E-205', 'sala' => null],
            (object) ['id' => 4, 'nombre' => 'Bodega Edificio Este', 'sala' => null],
            (object) ['id' => 5, 'nombre' => 'Pañol Central', 'sala' => null],
            (object) ['id' => 6, 'nombre' => 'Sin asignar', 'sala' => null],
        ];

        $migration = $this->migration();
        $codes = (new ReflectionMethod($migration, 'assignCodes'))->invoke($migration, $rows);

        $this->assertSame([1 => 'BODEGAE', 2 => 'PCENTRAL', 3 => 'E205', 4 => 'BEDIFICIOE', 5 => 'PCENTRAL2', 6 => 'SASIGNAR'], $codes);
    }

    public function test_migration_recomputes_cajon_barcodes_truncating_long_codes(): void
    {
        $location = Location::factory()->create(['code' => 'PREDES']);
        $ids = collect(['Rack-R1', 'Estantería superior izquierda', 'Estantería superior derecha'])
            ->map(fn (string $codigo, int $i) => DB::table('cajones')->insertGetId([
                'codigo' => $codigo, 'barcode' => 'EST-TEST-'.$i, 'location_id' => $location->id,
                'created_at' => now(), 'updated_at' => now(),
            ]));

        $migration = $this->migration();
        (new ReflectionMethod($migration, 'recomputeCajonBarcodes'))->invoke($migration);

        $this->assertSame(
            ['PREDES-RACK-R1', 'PREDES-ESTANTERIA-S', 'PREDES-ESTANTERIA-2'],
            $ids->map(fn (int $id) => DB::table('cajones')->where('id', $id)->value('barcode'))->all(),
        );
    }
}
