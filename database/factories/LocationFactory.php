<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\Storeroom;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    protected $model = Location::class;

    public function definition(): array
    {
        $nombre = 'Sala-'.fake()->unique()->numberBetween(100, 999);

        return [
            'storeroom_id' => fn () => Storeroom::default()->id,
            'nombre' => $nombre,
            'tipo' => fake()->randomElement(['sala', 'panol', 'taller']),
            'sala' => $nombre,
            'cajon' => 'Cajon-'.fake()->numberBetween(1, 50),
            'descripcion' => fake()->sentence(),
        ];
    }
}
