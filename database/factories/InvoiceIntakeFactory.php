<?php

namespace Database\Factories;

use App\Models\AnnualBudget;
use App\Models\InvoiceIntake;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceIntake>
 */
class InvoiceIntakeFactory extends Factory
{
    protected $model = InvoiceIntake::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'annual_budget_id' => AnnualBudget::factory(),
            'document_number' => (string) fake()->numerify('F-#####'),
            'status' => InvoiceIntake::STATUS_DRAFT,
        ];
    }
}
