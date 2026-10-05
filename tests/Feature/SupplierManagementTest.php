<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupplierManagementTest extends TestCase
{
    use DatabaseTransactions;

    protected User $director;

    protected User $warehouse;

    protected User $teacher;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->director = User::factory()->create([
            'email' => 'dir_prov_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_DIRECTOR,
            'is_active' => true,
        ]);

        $this->warehouse = User::factory()->create([
            'email' => 'panol_prov_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_WAREHOUSE,
            'is_active' => true,
        ]);

        $this->teacher = User::factory()->create([
            'email' => 'docente_prov_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_TEACHER,
            'is_active' => true,
        ]);

        $this->admin = User::factory()->create([
            'email' => 'admin_prov_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
    }

    public function test_director_can_create_supplier(): void
    {
        Sanctum::actingAs($this->director);

        $response = $this->postJson('/api/suppliers', [
            'name' => 'ElectroChile S.A.',
            'contact_name' => 'María Soto',
            'email' => 'ventas@electrochile.cl',
            'phone' => '+56912345678',
            'category' => 'Electrónica',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.name', 'ElectroChile S.A.');
        $response->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('suppliers', ['name' => 'ElectroChile S.A.']);
    }

    public function test_create_supplier_requires_name(): void
    {
        Sanctum::actingAs($this->director);

        $response = $this->postJson('/api/suppliers', ['contact_name' => 'Sin nombre']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('name');
    }

    public function test_warehouse_can_list_but_not_create_suppliers(): void
    {
        Supplier::factory()->create(['name' => 'Proveedor Listable SpA']);

        Sanctum::actingAs($this->warehouse);

        $this->getJson('/api/suppliers')
            ->assertStatus(200)
            ->assertJsonFragment(['name' => 'Proveedor Listable SpA']);

        $this->postJson('/api/suppliers', ['name' => 'No Debe Crearse'])->assertStatus(403);
    }

    public function test_teacher_cannot_access_suppliers(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->getJson('/api/suppliers')->assertStatus(403);
    }

    public function test_can_filter_suppliers_by_search_and_status(): void
    {
        $active = Supplier::factory()->create(['name' => 'Cables Andinos Ltda', 'is_active' => true]);
        $suspended = Supplier::factory()->create(['name' => 'Cables del Sur Ltda', 'is_active' => false]);

        Sanctum::actingAs($this->director);

        $this->getJson('/api/suppliers?search=Cables')
            ->assertStatus(200)
            ->assertJsonFragment(['id' => $active->id])
            ->assertJsonFragment(['id' => $suspended->id]);

        $this->getJson('/api/suppliers?is_active=false')
            ->assertStatus(200)
            ->assertJsonFragment(['id' => $suspended->id])
            ->assertJsonMissing(['id' => $active->id]);
    }

    public function test_suppliers_index_returns_products_count(): void
    {
        $supplier = Supplier::factory()->create();
        Product::factory()->create(['supplier_id' => $supplier->id]);

        Sanctum::actingAs($this->director);

        $this->getJson('/api/suppliers')
            ->assertStatus(200)
            ->assertJsonFragment(['products_count' => 1]);
    }

    public function test_supplier_detail_includes_supplied_products(): void
    {
        $supplier = Supplier::factory()->create();
        Product::factory()->create(['supplier_id' => $supplier->id, 'name' => 'Osciloscopio']);

        Sanctum::actingAs($this->director);

        $response = $this->getJson('/api/suppliers/' . $supplier->id);

        $response->assertStatus(200);
        $response->assertJsonPath('data.name', $supplier->name);
        $response->assertJsonPath('data.products.0.name', 'Osciloscopio');
    }

    public function test_director_can_update_supplier(): void
    {
        $supplier = Supplier::factory()->create();

        Sanctum::actingAs($this->director);

        $response = $this->putJson('/api/suppliers/' . $supplier->id, [
            'name' => 'ElectroChile Renombrada',
            'email' => 'nuevo@electrochile.cl',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.name', 'ElectroChile Renombrada');

        $this->assertEquals('nuevo@electrochile.cl', $supplier->fresh()->email);
    }

    public function test_can_suspend_and_reactivate_supplier(): void
    {
        $supplier = Supplier::factory()->create(['is_active' => true]);

        Sanctum::actingAs($this->director);

        $this->patchJson('/api/suppliers/' . $supplier->id . '/status', ['is_active' => false])
            ->assertStatus(200);

        $this->assertFalse($supplier->fresh()->is_active);

        $this->patchJson('/api/suppliers/' . $supplier->id . '/status', ['is_active' => true])
            ->assertStatus(200);

        $this->assertTrue($supplier->fresh()->is_active);
    }

    public function test_admin_can_delete_supplier_without_products(): void
    {
        $supplier = Supplier::factory()->create();

        Sanctum::actingAs($this->admin);

        $response = $this->deleteJson('/api/suppliers/' . $supplier->id);

        $response->assertStatus(200);
        $this->assertDatabaseMissing('suppliers', ['id' => $supplier->id]);
    }

    public function test_supplier_with_products_is_deactivated_instead_of_deleted(): void
    {
        $supplier = Supplier::factory()->create(['is_active' => true]);
        Product::factory()->create(['supplier_id' => $supplier->id]);

        Sanctum::actingAs($this->admin);

        $response = $this->deleteJson('/api/suppliers/' . $supplier->id);

        $response->assertStatus(200);
        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id, 'is_active' => false]);
    }
}
