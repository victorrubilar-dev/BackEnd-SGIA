<?php

namespace Database\Factories;

use App\Models\DashboardTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DashboardTemplate>
 */
class DashboardTemplateFactory extends Factory
{
    protected $model = DashboardTemplate::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->words(3, true),
            'description' => null,
        ];
    }
}
