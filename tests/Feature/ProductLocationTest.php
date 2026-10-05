<?php

namespace Tests\Feature;

use App\Models\Cajon;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductLocationTest extends TestCase
{
    use DatabaseTransactions;

    protected User $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = User::factory()->create([
            'email' => 'panol_loc_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_WAREHOUSE,
            'is_active' => true,
        ]);
    }

    public function test_can_assign_location_by_sala_and_cajon_on_product_creation(): void
    {
        Sanctum::actingAs($this->warehouse);

        $response = $this->postJson('/api/products', [
            'name' => 'Pinza Amperimetrica Digital',
            'quantity' => 10,
            'sala' => 'Lab-E3',
            'cajon' => 'Gaveta-07',
            'descripcion' => 'Sector de medición electrónica',
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'data' => [
                'name' => 'Pinza Amperimetrica Digital',
                'cajon' => [
                    'codigo' => 'Gaveta-07',
                    'descripcion' => 'Sector de medición electrónica',
                ],
                'location' => [
                    'sala' => 'Lab-E3',
                    'descripcion' => 'Sector de medición electrónica',
                ],
            ],
        ]);

        $this->assertDatabaseHas('locations', [
            'sala' => 'Lab-E3',
        ]);

        $this->assertDatabaseHas('cajones', [
            'codigo' => 'Gaveta-07',
        ]);
    }

    public function test_can_get_product_location(): void
    {
        Sanctum::actingAs($this->warehouse);

        $location = Location::factory()->create([
            'nombre' => 'Taller-1',
            'sala' => 'Taller-1',
            'descripcion' => 'Herramientas de mano',
        ]);

        $cajon = Cajon::factory()->create([
            'location_id' => $location->id,
            'codigo' => 'Cajon-B2',
            'descripcion' => 'Gaveta frontal',
        ]);

        $product = Product::factory()->create([
            'location_id' => $location->id,
            'cajon_id' => $cajon->id,
        ]);

        $response = $this->getJson('/api/products/' . $product->id . '/location');

        $response->assertStatus(200);
        $response->assertJson([
            'product_id' => $product->id,
            'cajon' => [
                'id' => $cajon->id,
                'codigo' => 'Cajon-B2',
            ],
            'location' => [
                'id' => $location->id,
                'sala' => 'Taller-1',
                'descripcion' => 'Herramientas de mano',
            ],
        ]);
    }

    public function test_can_update_product_location(): void
    {
        Sanctum::actingAs($this->warehouse);

        $product = Product::factory()->create();

        $response = $this->patchJson('/api/products/' . $product->id . '/location', [
            'sala' => 'Sala-Nueva-4',
            'cajon' => 'Cajon-12',
            'descripcion' => 'Nueva ubicación',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Ubicación física actualizada exitosamente.',
            'product' => [
                'cajon' => [
                    'codigo' => 'Cajon-12',
                ],
                'location' => [
                    'sala' => 'Sala-Nueva-4',
                ],
            ],
        ]);

        $product->refresh();
        $this->assertNotNull($product->cajon);
        $this->assertEquals('Cajon-12', $product->cajon->codigo);
        $this->assertEquals('Sala-Nueva-4', $product->cajon->location->sala);
    }

    public function test_can_filter_products_by_location(): void
    {
        Sanctum::actingAs($this->warehouse);

        $locationA = Location::factory()->create(['nombre' => 'Sala-E100', 'sala' => 'Sala-E100']);
        $cajonA = Cajon::factory()->create(['location_id' => $locationA->id, 'codigo' => 'Cajon-1']);

        $locationB = Location::factory()->create(['nombre' => 'Sala-E200', 'sala' => 'Sala-E200']);
        $cajonB = Cajon::factory()->create(['location_id' => $locationB->id, 'codigo' => 'Cajon-2']);

        $productA = Product::factory()->create(['location_id' => $locationA->id, 'cajon_id' => $cajonA->id]);
        $productB = Product::factory()->create(['location_id' => $locationB->id, 'cajon_id' => $cajonB->id]);

        $response = $this->getJson('/api/products?sala=Sala-E100');

        $response->assertStatus(200);
        $response->assertJsonFragment(['id' => $productA->id]);
        $response->assertJsonMissing(['id' => $productB->id]);
    }
}
