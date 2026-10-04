<?php

namespace Database\Factories;

use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    protected $model = Location::class;

    public function definition(): array
    {
        return [
            'sala' => 'Sala-' . fake()->unique()->numberBetween(100, 999),
            'cajon' => 'Cajon-' . fake()->numberBetween(1, 50),
            'descripcion' => fake()->sentence(),
        ];
    }
}
