<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductManagementTest extends TestCase
{
    use DatabaseTransactions;

    protected User $warehouse;
    protected User $teacher;
    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = User::factory()->create([
            'email' => 'panol_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_WAREHOUSE,
            'is_active' => true,
        ]);

        $this->teacher = User::factory()->create([
            'email' => 'profe_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_TEACHER,
            'is_active' => true,
        ]);

        $this->supplier = Supplier::factory()->create();
    }

    public function test_warehouse_can_create_product_and_barcode_is_generated_automatically(): void
    {
        Sanctum::actingAs($this->warehouse);

        $response = $this->postJson('/api/products', [
            'name' => 'Cautin de Soldar 60W Pro',
            'description' => 'Cautín profesional para laboratorio de electrónica',
            'quantity' => 15,
            'stock_minimo' => 5,
            'supplier_id' => $this->supplier->id,
            'area' => 'Electrónica',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => [
                'id',
                'name',
                'barcode',
                'quantity',
                'stock_minimo',
                'supplier_id',
                'area',
                'is_active',
            ],
        ]);

        $barcode = $response->json('data.barcode');
        $this->assertNotEmpty($barcode);
        $this->assertStringStartsWith('SGIA-', $barcode);

        $this->assertDatabaseHas('products', [
            'name' => 'Cautin de Soldar 60W Pro',
            'barcode' => $barcode,
            'quantity' => 15,
        ]);
    }

    public function test_teacher_cannot_create_or_modify_products(): void
    {
        Sanctum::actingAs($this->teacher);

        $responseCreate = $this->postJson('/api/products', [
            'name' => 'Intento Docente',
            'quantity' => 5,
            'supplier_id' => $this->supplier->id,
        ]);
        $responseCreate->assertStatus(403);

        $product = Product::factory()->create();

        $responseUpdate = $this->patchJson('/api/products/' . $product->id, [
            'name' => 'Nombre Modificado',
        ]);
        $responseUpdate->assertStatus(403);

        $responseStatus = $this->patchJson('/api/products/' . $product->id . '/status', [
            'is_active' => false,
        ]);
        $responseStatus->assertStatus(403);
    }

    public function test_warehouse_can_retrieve_barcode_svg_and_image_uri(): void
    {
        Sanctum::actingAs($this->warehouse);

        $product = Product::factory()->create([
            'barcode' => 'SGIA-98765432',
        ]);

        $response = $this->getJson('/api/products/' . $product->id . '/barcode');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'product_id',
            'product_name',
            'barcode',
            'barcode_svg',
            'barcode_image_uri',
            'barcode_html',
        ]);

        $this->assertEquals('SGIA-98765432', $response->json('barcode'));
        $this->assertStringContainsString('<svg', $response->json('barcode_svg'));
        $this->assertNotEmpty($response->json('barcode_image_uri'));
    }

    public function test_validations_name_without_special_chars_positive_quantity_and_existing_supplier(): void
    {
        Sanctum::actingAs($this->warehouse);

        // Name with invalid special chars (e.g. $, <>, etc.)
        $resName = $this->postJson('/api/products', [
            'name' => 'Multimetro $$$ <script>',
            'quantity' => 5,
            'supplier_id' => $this->supplier->id,
        ]);
        $resName->assertStatus(422);
        $resName->assertJsonValidationErrors(['name']);

        // Negative quantity
        $resNegative = $this->postJson('/api/products', [
            'name' => 'Multimetro Digital',
            'quantity' => -3,
            'supplier_id' => $this->supplier->id,
        ]);
        $resNegative->assertStatus(422);
        $resNegative->assertJsonValidationErrors(['quantity']);

        // Non-existing supplier
        $resSupplier = $this->postJson('/api/products', [
            'name' => 'Multimetro Digital',
            'quantity' => 10,
            'supplier_id' => 999999,
        ]);
        $resSupplier->assertStatus(422);
        $resSupplier->assertJsonValidationErrors(['supplier_id']);
    }

    public function test_warehouse_can_update_product_and_change_status(): void
    {
        Sanctum::actingAs($this->warehouse);

        $product = Product::factory()->create([
            'name' => 'Osciloscopio Original',
            'quantity' => 10,
            'is_active' => true,
        ]);

        $updateResponse = $this->patchJson('/api/products/' . $product->id, [
            'name' => 'Osciloscopio Actualizado',
            'quantity' => 12,
        ]);

        $updateResponse->assertStatus(200);
        $this->assertEquals('Osciloscopio Actualizado', $updateResponse->json('data.name'));
        $this->assertEquals(12, $updateResponse->json('data.quantity'));

        $statusResponse = $this->patchJson('/api/products/' . $product->id . '/status', [
            'is_active' => false,
        ]);

        $statusResponse->assertStatus(200);
        $product->refresh();
        $this->assertFalse($product->is_active);
    }
}
