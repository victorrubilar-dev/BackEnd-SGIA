<?php

namespace Database\Factories;

use App\Models\ChartTemplate;
use App\Models\User;
use App\Models\UserChart;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserChart>
 */
class UserChartFactory extends Factory
{
    protected $model = UserChart::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'chart_template_id' => ChartTemplate::factory(),
            'custom_title' => fake()->words(3, true),
            'type' => 'bar',
            'position' => 1,
            'entity' => 'EQUIPMENT',
            'metric' => 'COUNT',
            'group_by' => 'location',
            'period' => 'LAST_30_DAYS',
            'is_active' => true,
        ];
    }
}
