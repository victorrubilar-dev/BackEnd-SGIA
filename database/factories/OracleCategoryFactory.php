<?php

namespace Database\Factories;

use App\Models\OracleCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OracleCategory>
 */
class OracleCategoryFactory extends Factory
{
    protected $model = OracleCategory::class;

    public function definition(): array
    {
        return [
            'name' => 'PRUEBA.'.mb_strtoupper(fake()->unique()->words(2, true)),
        ];
    }
}
