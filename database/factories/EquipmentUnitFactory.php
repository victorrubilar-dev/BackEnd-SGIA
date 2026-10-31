<?php

namespace Database\Factories;

use App\Models\Cajon;
use App\Models\EquipmentUnit;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EquipmentUnit>
 */
class EquipmentUnitFactory extends Factory
{
    protected $model = EquipmentUnit::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'cajon_id' => Cajon::factory(),
            'code' => 'PRO-IC-'.fake()->unique()->numerify('######'),
            'serial_number' => null,
            'status' => EquipmentUnit::STATUS_AVAILABLE,
        ];
    }

    public function available(): static
    {
        return $this->state(['status' => EquipmentUnit::STATUS_AVAILABLE]);
    }

    public function onLoan(): static
    {
        return $this->state(['status' => EquipmentUnit::STATUS_ON_LOAN]);
    }

    public function inRepair(): static
    {
        return $this->state(['status' => EquipmentUnit::STATUS_IN_REPAIR]);
    }

    public function writtenOff(): static
    {
        return $this->state(['status' => EquipmentUnit::STATUS_WRITTEN_OFF]);
    }
}
