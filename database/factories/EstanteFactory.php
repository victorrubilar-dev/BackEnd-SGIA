<?php

namespace Database\Factories;

use App\Models\Estante;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Estante>
 */
class EstanteFactory extends Factory
{
    protected $model = Estante::class;

    public function definition(): array
    {
        return [
            'location_id' => Location::factory(),
            'zona_id' => null,
            'codigo' => 'EST-'.fake()->unique()->numberBetween(1, 99999),
            'x' => 0,
            'y' => 0,
            'width' => 100,
            'depth' => 40,
            'rotation' => 0,
        ];
    }
}
