<?php

namespace Database\Factories;

use App\Models\Career;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Career>
 */
class CareerFactory extends Factory
{
    protected $model = Career::class;

    public function definition(): array
    {
        return [
            'name' => 'Carrera '.fake()->unique()->words(2, true),
            'level' => Career::LEVEL_TECHNICIAN,
        ];
    }
}
