<?php

namespace Database\Factories;

use App\Models\Loan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Loan>
 */
class LoanFactory extends Factory
{
    protected $model = Loan::class;

    public function definition(): array
    {
        return [
            'code' => 'REM-' . date('Y') . '-' . strtoupper(fake()->unique()->bothify('??????')),
            'type' => Loan::TYPE_REMOTE,
            'requested_by' => User::factory(),
            'borrower_name' => fake()->name(),
            'borrower_document' => null,
            'subject' => 'Taller de Electricidad',
            'room' => 'Sala 201',
            'loan_date' => now()->addDays(2)->toDateString(),
            'time_block' => 'Bloque 1-3',
            'status' => Loan::STATUS_PENDING,
            'rejection_reason' => null,
            'approved_by' => null,
            'approved_at' => null,
            'processed_by' => null,
            'processed_at' => null,
            'notes' => null,
        ];
    }

    public function inProgress(): static
    {
        return $this->state(fn () => [
            'status' => Loan::STATUS_IN_PROGRESS,
        ]);
    }

    public function processed(): static
    {
        return $this->state(fn () => [
            'status' => Loan::STATUS_PROCESSED,
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'status' => Loan::STATUS_REJECTED,
            'rejection_reason' => 'Material reservado para otra sección.',
        ]);
    }
}
