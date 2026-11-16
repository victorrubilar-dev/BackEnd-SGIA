<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\OracleCategory;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsProductPayload;
use Tests\TestCase;

/**
 * Código maestro (`Art` de Oracle) y descripción de categoría del maestro en los productos.
 */
class OracleMasterFieldsTest extends TestCase
{
    use BuildsProductPayload;
    use DatabaseTransactions;

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        Sanctum::actingAs($user);

        return $user;
    }

    /** @return array<string, mixed> */
    private function supplyPayload(array $extra = []): array
    {
        return [
            'name' => 'Caldo deshidratado',
            'type' => Product::TYPE_SUPPLY,
            'quantity' => 3,
            'barcode' => 'TEST-'.uniqid(),
            'category_id' => Category::factory()->create()->id,
            ...$extra,
        ];
    }

    public function test_migration_loads_the_categories_from_the_oracle_master(): void
    {
        $this->assertGreaterThanOrEqual(62, OracleCategory::count());
        $this->assertDatabaseHas('oracle_categories', ['name' => 'ACTIVO FIJO']);
        $this->assertDatabaseHas('oracle_categories', ['name' => 'INSUMOS.MATERIALES']);
    }

    public function test_warehouse_lists_the_full_catalog_and_can_search(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);

        $all = $this->getJson('/api/oracle-categories')->assertOk();
        $this->assertSame(OracleCategory::count(), count($all->json('data')));
        $all->assertJsonStructure(['data' => [['id', 'name']]]);

        $this->getJson('/api/oracle-categories?search=activo fijo')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'ACTIVO FIJO');
    }

    public function test_director_can_add_a_new_category_normalized_to_uppercase(): void
    {
        $user = $this->actingAsRole(User::ROLE_DIRECTOR);

        $this->postJson('/api/oracle-categories', ['name' => '  ti.equipos   de red '])
            ->assertCreated()
            ->assertJsonPath('data.name', 'TI.EQUIPOS DE RED');

        $this->assertDatabaseHas('oracle_categories', ['name' => 'TI.EQUIPOS DE RED', 'created_by' => $user->id]);
    }

    public function test_new_category_validation(): void
    {
        $this->actingAsRole(User::ROLE_ADMIN);

        $this->postJson('/api/oracle-categories', [])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/oracle-categories', ['name' => str_repeat('A', 101)])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/oracle-categories', ['name' => 'activo fijo'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'Esa descripción de categoría ya existe.');
    }

    public function test_teacher_cannot_use_the_catalog(): void
    {
        $this->actingAsRole(User::ROLE_TEACHER);

        $this->getJson('/api/oracle-categories')->assertForbidden();
        $this->postJson('/api/oracle-categories', ['name' => 'X'])->assertForbidden();
    }

    public function test_product_can_be_created_without_the_oracle_fields(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);

        $this->postJson('/api/products', $this->supplyPayload())
            ->assertCreated()
            ->assertJsonPath('data.master_code', null)
            ->assertJsonPath('data.oracle_category_id', null)
            ->assertJsonPath('data.oracle_category', null);
    }

    public function test_product_stores_master_code_and_category(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $category = OracleCategory::where('name', 'INSUMOS.MATERIALES')->firstOrFail();

        $response = $this->postJson('/api/products', $this->supplyPayload([
            'master_code' => ' of31393 ',
            'oracle_category_id' => $category->id,
        ]))->assertCreated()
            ->assertJsonPath('data.master_code', 'OF31393')
            ->assertJsonPath('data.oracle_category_id', $category->id)
            ->assertJsonPath('data.oracle_category.name', 'INSUMOS.MATERIALES');

        $id = $response->json('data.id');
        $this->getJson("/api/products/{$id}")->assertOk()->assertJsonPath('data.oracle_category.name', 'INSUMOS.MATERIALES');
        $this->getJson('/api/products?search=OF31393')->assertOk()->assertJsonPath('data.0.id', $id);
    }

    public function test_product_oracle_fields_can_be_changed_and_cleared(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $category = OracleCategory::factory()->create();
        $product = Product::factory()->create(['master_code' => 'OF00001']);

        $this->patchJson("/api/products/{$product->id}", ['master_code' => 'OF00002', 'oracle_category_id' => $category->id])
            ->assertOk()
            ->assertJsonPath('data.master_code', 'OF00002')
            ->assertJsonPath('data.oracle_category.name', $category->name);

        // Guardar el mismo código en el mismo producto no choca con la unicidad.
        $this->patchJson("/api/products/{$product->id}", ['master_code' => 'OF00002'])->assertOk();

        $this->patchJson("/api/products/{$product->id}", ['master_code' => '', 'oracle_category_id' => null])
            ->assertOk()
            ->assertJsonPath('data.master_code', null)
            ->assertJsonPath('data.oracle_category_id', null);

        // Un PATCH sin los campos no los toca.
        $product->update(['master_code' => 'OF00003', 'oracle_category_id' => $category->id]);
        $this->patchJson("/api/products/{$product->id}", ['stock_minimo' => 2])
            ->assertOk()
            ->assertJsonPath('data.master_code', 'OF00003')
            ->assertJsonPath('data.oracle_category_id', $category->id);
    }

    public function test_master_code_is_unique_and_validated(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        Product::factory()->create(['master_code' => 'OF33075']);
        $other = Product::factory()->create();

        $this->postJson('/api/products', $this->supplyPayload(['master_code' => 'of33075']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.master_code.0', 'El código maestro ya está asignado a otro producto.');

        $this->patchJson("/api/products/{$other->id}", ['master_code' => 'OF33075'])
            ->assertUnprocessable()->assertJsonValidationErrors('master_code');

        $this->patchJson("/api/products/{$other->id}", ['master_code' => 'OF 33075'])
            ->assertUnprocessable()->assertJsonValidationErrors('master_code');

        $this->patchJson("/api/products/{$other->id}", ['master_code' => str_repeat('A', 31)])
            ->assertUnprocessable()->assertJsonValidationErrors('master_code');

        $this->patchJson("/api/products/{$other->id}", ['oracle_category_id' => 999999])
            ->assertUnprocessable()->assertJsonValidationErrors('oracle_category_id');
    }

    public function test_category_in_use_cannot_be_deleted(): void
    {
        $category = OracleCategory::factory()->create();
        Product::factory()->create(['oracle_category_id' => $category->id]);

        $this->expectException(QueryException::class);
        $category->delete();
    }
}
