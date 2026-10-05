<?php

namespace Database\Factories;

use App\Models\Quotation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Quotation>
 */
class QuotationFactory extends Factory
{
    protected $model = Quotation::class;

    public function definition(): array
    {
        return [
            'code' => 'COT-' . fake()->unique()->numberBetween(202600000, 202699999),
            'created_by' => User::factory(),
            'status' => Quotation::STATUS_PENDING,
            'notes' => fake()->sentence(),
            'expires_at' => fake()->dateTimeBetween('+1 week', '+2 months')->format('Y-m-d'),
            'purchase_id' => null,
            'converted_at' => null,
        ];
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => ['status' => Quotation::STATUS_ACCEPTED]);
    }

    public function converted(): static
    {
        return $this->state(fn (array $attributes) => ['status' => Quotation::STATUS_CONVERTED]);
    }
}
