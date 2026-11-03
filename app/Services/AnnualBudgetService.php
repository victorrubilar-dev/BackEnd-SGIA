<?php

namespace App\Services;

use App\Models\AnnualBudget;
use App\Models\BudgetMovement;
use App\Models\InvoiceIntake;
use App\Models\Purchase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Única vía de escritura de montos de un presupuesto.
 *
 * Invariante: `available = initial − consumido − reservado`, nunca negativo.
 * Cada operación bloquea el presupuesto y deja un movimiento en `budget_movements`.
 */
class AnnualBudgetService
{
    /**
     * Crea el presupuesto (ingreso manual por carrera y año) y registra la asignación inicial.
     *
     * @param  array{career_id: int, year: int, initial_amount: string|float|int}  $data
     */
    public function create(array $data, int $userId): AnnualBudget
    {
        return DB::transaction(function () use ($data, $userId) {
            $budget = AnnualBudget::create([
                'career_id' => $data['career_id'],
                'year' => $data['year'],
                'initial_amount' => $this->cents($data['initial_amount']) / 100,
                'available_amount' => $this->cents($data['initial_amount']) / 100,
                'reserved_amount' => 0,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            $this->record($budget, BudgetMovement::TYPE_INITIAL, $this->cents($data['initial_amount']), $userId);

            return $budget;
        });
    }

    /**
     * Cambia el monto inicial y recalcula `available = initial − consumido − reservado`.
     * `available_amount` nunca se edita directo.
     *
     * @throws ValidationException si el nuevo monto no cubre lo consumido más lo reservado
     */
    public function updateInitialAmount(AnnualBudget $budget, string|float|int $initialAmount, int $userId): AnnualBudget
    {
        return DB::transaction(function () use ($budget, $initialAmount, $userId) {
            $locked = $this->lock($budget);

            $previousCents = $this->cents($locked->initial_amount);
            $newCents = $this->cents($initialAmount);
            $committedCents = $this->consumedCents($locked) + $this->cents($locked->reserved_amount);

            if ($newCents < $committedCents) {
                throw ValidationException::withMessages([
                    'initial_amount' => 'El monto inicial no puede ser menor que lo ya consumido y reservado ($'.$this->format($committedCents).').',
                ]);
            }

            $locked->update([
                'initial_amount' => $newCents / 100,
                'available_amount' => ($newCents - $committedCents) / 100,
                'updated_by' => $userId,
            ]);

            $this->record(
                $locked, BudgetMovement::TYPE_ADJUSTMENT, abs($newCents - $previousCents), $userId,
                notes: 'Monto inicial: $'.$this->format($previousCents).' → $'.$this->format($newCents)
            );

            return $locked;
        });
    }

    /**
     * Reserva `$amount` para la compra (crear la compra equivale a aprobarla).
     *
     * @throws ValidationException si supera el disponible
     */
    public function reserve(AnnualBudget $budget, string|float|int $amount, Purchase $purchase, int $userId): AnnualBudget
    {
        return DB::transaction(function () use ($budget, $amount, $purchase, $userId) {
            $locked = $this->lock($budget);
            $cents = $this->cents($amount);

            if ($cents > $this->cents($locked->available_amount)) {
                throw ValidationException::withMessages([
                    'total' => 'El total de la compra ($'.$this->format($cents).') supera el presupuesto disponible ($'.$this->format($this->cents($locked->available_amount)).').',
                ]);
            }

            $this->apply($locked, availableDelta: -$cents, reservedDelta: $cents, userId: $userId);
            $purchase->forceFill(['reserved_amount' => $cents / 100])->save();
            $this->record($locked, BudgetMovement::TYPE_RESERVE, $cents, $userId, purchase: $purchase);

            return $locked;
        });
    }

    /**
     * Libera la reserva vigente de la compra y la devuelve al disponible.
     */
    public function releaseReservation(AnnualBudget $budget, Purchase $purchase, int $userId, ?InvoiceIntake $intake = null): AnnualBudget
    {
        return DB::transaction(function () use ($budget, $purchase, $userId, $intake) {
            $locked = $this->lock($budget);
            $cents = $this->cents($purchase->reserved_amount);

            if ($cents === 0) {
                return $locked;
            }

            $this->apply($locked, availableDelta: $cents, reservedDelta: -$cents, userId: $userId);
            $purchase->forceFill(['reserved_amount' => 0])->save();
            $this->record($locked, BudgetMovement::TYPE_RELEASE, $cents, $userId, purchase: $purchase, intake: $intake);

            return $locked;
        });
    }

    /**
     * Consume `$amount` del disponible (factura confirmada).
     *
     * @throws ValidationException si supera el disponible
     */
    public function consume(AnnualBudget $budget, string|float|int $amount, InvoiceIntake $intake, int $userId, ?Purchase $purchase = null): AnnualBudget
    {
        return DB::transaction(function () use ($budget, $amount, $intake, $userId, $purchase) {
            $locked = $this->lock($budget);
            $cents = $this->cents($amount);

            if ($cents > $this->cents($locked->available_amount)) {
                throw ValidationException::withMessages([
                    'items' => 'El total de la factura ($'.$this->format($cents).') supera el presupuesto disponible ($'.$this->format($this->cents($locked->available_amount)).').',
                ]);
            }

            $this->apply($locked, availableDelta: -$cents, reservedDelta: 0, userId: $userId);
            $this->record($locked, BudgetMovement::TYPE_CONSUME, $cents, $userId, purchase: $purchase, intake: $intake);

            return $locked;
        });
    }

    /**
     * Repone `$amount` al disponible (anulación de una factura confirmada).
     */
    public function restore(AnnualBudget $budget, string|float|int $amount, InvoiceIntake $intake, int $userId): AnnualBudget
    {
        return DB::transaction(function () use ($budget, $amount, $intake, $userId) {
            $locked = $this->lock($budget);
            $cents = $this->cents($amount);

            $this->apply($locked, availableDelta: $cents, reservedDelta: 0, userId: $userId);
            $this->record($locked, BudgetMovement::TYPE_RESTORE, $cents, $userId, intake: $intake);

            return $locked;
        });
    }

    /** Consumido en centavos: `initial − available − reserved`. */
    public function consumedCents(AnnualBudget $budget): int
    {
        return $this->cents($budget->initial_amount)
            - $this->cents($budget->available_amount)
            - $this->cents($budget->reserved_amount);
    }

    public function cents(string|float|int $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private function lock(AnnualBudget $budget): AnnualBudget
    {
        return AnnualBudget::whereKey($budget->id)->lockForUpdate()->firstOrFail();
    }

    private function apply(AnnualBudget $budget, int $availableDelta, int $reservedDelta, int $userId): void
    {
        $budget->update([
            'available_amount' => ($this->cents($budget->available_amount) + $availableDelta) / 100,
            'reserved_amount' => ($this->cents($budget->reserved_amount) + $reservedDelta) / 100,
            'updated_by' => $userId,
        ]);
    }

    private function record(
        AnnualBudget $budget,
        string $type,
        int $cents,
        int $userId,
        ?Purchase $purchase = null,
        ?InvoiceIntake $intake = null,
        ?string $notes = null,
    ): void {
        BudgetMovement::create([
            'annual_budget_id' => $budget->id,
            'type' => $type,
            'amount' => $cents / 100,
            'purchase_id' => $purchase?->id,
            'invoice_intake_id' => $intake?->id,
            'user_id' => $userId,
            'notes' => $notes,
        ]);
    }

    private function format(int $cents): string
    {
        return number_format($cents / 100, 0, ',', '.');
    }
}
