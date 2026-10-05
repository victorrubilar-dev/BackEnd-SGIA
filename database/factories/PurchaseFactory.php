<?php

namespace Database\Factories;

use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Purchase>
 */
class PurchaseFactory extends Factory
{
    protected $model = Purchase::class;

    public function definition(): array
    {
        return [
            'code' => 'OC-' . fake()->unique()->numberBetween(202600000, 202699999),
            'supplier_id' => Supplier::factory(),
            'created_by' => User::factory(),
            'status' => Purchase::STATUS_PENDING,
            'quotation_reference' => null,
            'expected_at' => fake()->dateTimeBetween('+1 week', '+1 month')->format('Y-m-d'),
            'total' => fake()->randomFloat(2, 10000, 500000),
            'notes' => fake()->sentence(),
            'guide_number' => null,
            'invoice_number' => null,
            'arrival_document' => null,
            'received_at' => null,
        ];
    }

    public function status(string $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }

    public function inTransit(): static
    {
        return $this->status(Purchase::STATUS_IN_TRANSIT);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Purchase::STATUS_COMPLETED,
            'received_at' => now(),
        ]);
    }
}
