<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Áreas de proveedor (nuevo flujo de compras, Fase 1).
 */
class SupplierAreaTest extends TestCase
{
    use DatabaseTransactions;

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_create_requires_at_least_one_valid_area(): void
    {
        $this->actingAsRole(User::ROLE_DIRECTOR);

        $this->postJson('/api/suppliers', ['name' => 'Sin área'])
            ->assertStatus(422)->assertJsonValidationErrors('areas');
        $this->postJson('/api/suppliers', ['name' => 'Vacía', 'areas' => []])
            ->assertStatus(422)->assertJsonValidationErrors('areas');
        $this->postJson('/api/suppliers', ['name' => 'Inválida', 'areas' => ['electricidad']])
            ->assertStatus(422)->assertJsonValidationErrors('areas.0');
        $this->postJson('/api/suppliers', ['name' => 'Repetida', 'areas' => ['diseno', 'diseno']])
            ->assertStatus(422)->assertJsonValidationErrors('areas.0');
    }

    public function test_supplier_can_have_all_areas(): void
    {
        $this->actingAsRole(User::ROLE_ADMIN);

        $this->postJson('/api/suppliers', [
            'name' => 'Multiárea',
            'areas' => ['telecomunicaciones', 'informatica', 'diseno', 'ciberseguridad'],
        ])->assertCreated()
            ->assertJsonPath('data.areas', ['ciberseguridad', 'diseno', 'informatica', 'telecomunicaciones']);
    }

    public function test_update_replaces_areas_and_keeps_them_when_omitted(): void
    {
        $this->actingAsRole(User::ROLE_DIRECTOR);
        $supplier = Supplier::factory()->withAreas(['informatica', 'diseno'])->create();

        $this->putJson("/api/suppliers/{$supplier->id}", ['areas' => ['ciberseguridad']])
            ->assertOk()->assertJsonPath('data.areas', ['ciberseguridad']);

        $this->patchJson("/api/suppliers/{$supplier->id}", ['name' => 'Renombrado'])
            ->assertOk()->assertJsonPath('data.areas', ['ciberseguridad']);

        $this->patchJson("/api/suppliers/{$supplier->id}", ['areas' => []])
            ->assertStatus(422)->assertJsonValidationErrors('areas');
    }

    public function test_editing_legacy_supplier_without_areas_requires_one(): void
    {
        $this->actingAsRole(User::ROLE_DIRECTOR);
        $supplier = Supplier::factory()->create();
        DB::table('supplier_areas')->where('supplier_id', $supplier->id)->delete();

        $this->patchJson("/api/suppliers/{$supplier->id}", ['name' => 'Viejo'])
            ->assertStatus(422)->assertJsonValidationErrors('areas');

        $this->patchJson("/api/suppliers/{$supplier->id}", ['name' => 'Viejo', 'areas' => ['telecomunicaciones']])
            ->assertOk()->assertJsonPath('data.areas', ['telecomunicaciones']);
    }

    public function test_index_filters_by_area(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $design = Supplier::factory()->withAreas(['diseno'])->create();
        $both = Supplier::factory()->withAreas(['diseno', 'ciberseguridad'])->create();
        $other = Supplier::factory()->withAreas(['informatica'])->create();

        $ids = collect($this->getJson('/api/suppliers?area=diseno&per_page=100')->assertOk()->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($design->id));
        $this->assertTrue($ids->contains($both->id));
        $this->assertFalse($ids->contains($other->id));

        $this->getJson('/api/suppliers?area=electricidad')->assertStatus(422)->assertJsonValidationErrors('area');
    }

    public function test_teacher_cannot_create_supplier(): void
    {
        $this->actingAsRole(User::ROLE_TEACHER);

        $this->postJson('/api/suppliers', ['name' => 'X', 'areas' => ['diseno']])->assertForbidden();
    }
}
