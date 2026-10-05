<?php

namespace Database\Factories;

use App\Models\Cajon;
use App\Models\Location;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $location = Location::factory()->create();
        $cajon = Cajon::factory()->create(['location_id' => $location->id]);

        return [
            'name' => 'Producto ' . fake()->unique()->words(2, true),
            'description' => fake()->paragraph(),
            'barcode' => 'SGIA-' . fake()->unique()->numerify('##########'),
            'quantity' => fake()->numberBetween(10, 100),
            'stock_minimo' => 5,
            'supplier_id' => Supplier::factory(),
            'cajon_id' => $cajon->id,
            'location_id' => $location->id,
            'area' => 'Electricidad',
            'photo_url' => null,
            'is_active' => true,
        ];
    }
}
