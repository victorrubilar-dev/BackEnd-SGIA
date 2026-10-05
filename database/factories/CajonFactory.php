<?php

namespace Database\Factories;

use App\Models\Cajon;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cajon>
 */
class CajonFactory extends Factory
{
    protected $model = Cajon::class;

    public function definition(): array
    {
        return [
            'codigo' => 'Cajon-' . fake()->unique()->numberBetween(1, 9999),
            'descripcion' => fake()->sentence(),
            'location_id' => Location::factory(),
        ];
    }
}
