<?php

namespace Database\Factories;

use App\Models\ChartTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChartTemplate>
 */
class ChartTemplateFactory extends Factory
{
    protected $model = ChartTemplate::class;

    public function definition(): array
    {
        return [
            'code' => fake()->unique()->lexify('tpl_????????'),
            'name' => fake()->words(2, true),
            'visualization_type' => 'bar',
            'description' => null,
        ];
    }
}
