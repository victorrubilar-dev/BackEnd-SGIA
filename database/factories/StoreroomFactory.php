<?php

namespace Database\Factories;

use App\Models\Storeroom;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Storeroom>
 */
class StoreroomFactory extends Factory
{
    protected $model = Storeroom::class;

    public function definition(): array
    {
        return [
            'name' => 'Pañol '.fake()->unique()->word(),
            'code' => 'PAN-'.strtoupper(fake()->unique()->bothify('????##')),
            'general_location' => fake()->streetName(),
        ];
    }
}
