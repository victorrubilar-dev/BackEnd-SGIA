<?php

namespace App\Services;

use App\Models\AnnualBudget;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AnnualBudgetService
{
    /**
     * Cambia el monto inicial y recalcula `available_amount = initial − consumido`.
     * El consumido es `initial − available` vigente; `available_amount` nunca se edita directo.
     *
     * @throws ValidationException si el disponible quedaría negativo
     */
    public function updateInitialAmount(AnnualBudget $budget, string|float|int $initialAmount, int $userId): AnnualBudget
    {
        return DB::transaction(function () use ($budget, $initialAmount, $userId) {
            $locked = AnnualBudget::whereKey($budget->id)->lockForUpdate()->firstOrFail();

            $consumedCents = $this->cents($locked->initial_amount) - $this->cents($locked->available_amount);
            $availableCents = $this->cents($initialAmount) - $consumedCents;

            if ($availableCents < 0) {
                throw ValidationException::withMessages([
                    'initial_amount' => 'El monto inicial no puede ser menor que lo ya consumido ($'.number_format($consumedCents / 100, 0, ',', '.').').',
                ]);
            }

            $locked->update([
                'initial_amount' => $this->cents($initialAmount) / 100,
                'available_amount' => $availableCents / 100,
                'updated_by' => $userId,
            ]);

            return $locked;
        });
    }

    public function cents(string|float|int $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
