<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\Zona;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Zona>
 */
class ZonaFactory extends Factory
{
    protected $model = Zona::class;

    public function definition(): array
    {
        return [
            'location_id' => Location::factory(),
            'nombre' => 'Zona '.fake()->unique()->numberBetween(1, 99999),
            'x' => 0,
            'y' => 0,
            'width' => 300,
            'depth' => 300,
        ];
    }
}
