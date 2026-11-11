<?php

namespace Database\Factories;

use App\Enums\SupplierArea;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'contact_name' => fake()->name(),
            'email' => fake()->unique()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'category' => fake()->word(),
            'is_active' => true,
        ];
    }

    /** Todo proveedor tiene al menos un área (por defecto `informatica`). */
    public function configure(): static
    {
        return $this->afterCreating(function (Supplier $supplier) {
            if ($supplier->areas()->doesntExist()) {
                $supplier->areas()->create(['area' => SupplierArea::Informatica->value]);
            }
            $supplier->unsetRelation('areas');
        });
    }

    /**
     * @param  array<int, string|SupplierArea>  $areas
     */
    public function withAreas(array $areas): static
    {
        return $this->afterCreating(function (Supplier $supplier) use ($areas) {
            $supplier->syncAreas(array_map(fn ($a) => $a instanceof SupplierArea ? $a->value : $a, $areas));
        });
    }
}
