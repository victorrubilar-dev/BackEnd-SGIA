<?php

namespace Database\Factories;

use App\Models\AnnualBudget;
use App\Models\Career;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnnualBudget>
 */
class AnnualBudgetFactory extends Factory
{
    protected $model = AnnualBudget::class;

    public function definition(): array
    {
        return [
            'career_id' => Career::factory(),
            'year' => fake()->unique()->numberBetween(2000, 2099),
            'initial_amount' => 1000000,
            'available_amount' => 1000000,
        ];
    }
}
