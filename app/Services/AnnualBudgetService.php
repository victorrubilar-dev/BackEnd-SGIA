<?php

namespace App\Services;

use App\Exceptions\BudgetExceededException;
use App\Models\AnnualBudget;
use App\Models\BudgetMovement;
use App\Models\InvoiceIntake;
use App\Models\Purchase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Única vía de escritura de montos de un presupuesto.
 *
 * Invariante: `available = initial − consumido` (la reserva no lo baja); `free = available − reserved`
 * es lo gastable. Solo una factura autorizada por DIR-01 puede dejar `free` bajo cero.
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
     * Cambia el monto inicial y recalcula `available = initial − consumido`.
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
                'available_amount' => ($newCents - $this->consumedCents($locked)) / 100,
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
     * @throws ValidationException si supera lo libre
     */
    public function reserve(AnnualBudget $budget, string|float|int $amount, Purchase $purchase, int $userId): AnnualBudget
    {
        return DB::transaction(function () use ($budget, $amount, $purchase, $userId) {
            $locked = $this->lock($budget);
            $cents = $this->cents($amount);

            if ($cents > $this->freeCents($locked)) {
                throw ValidationException::withMessages([
                    'total' => 'El total de la compra ($'.$this->format($cents).') supera el presupuesto libre ($'.$this->format($this->freeCents($locked)).').',
                ]);
            }

            $this->apply($locked, availableDelta: 0, reservedDelta: $cents, userId: $userId);
            $purchase->forceFill(['reserved_amount' => $cents / 100])->save();
            $this->record($locked, BudgetMovement::TYPE_RESERVE, $cents, $userId, purchase: $purchase);

            return $locked;
        });
    }

    /**
     * Libera la reserva vigente de la compra (el disponible no cambia).
     */
    public function releaseReservation(AnnualBudget $budget, Purchase $purchase, int $userId, ?InvoiceIntake $intake = null): AnnualBudget
    {
        return DB::transaction(function () use ($budget, $purchase, $userId, $intake) {
            $locked = $this->lock($budget);
            $cents = $this->cents($purchase->reserved_amount);

            if ($cents === 0) {
                return $locked;
            }

            $this->apply($locked, availableDelta: 0, reservedDelta: -$cents, userId: $userId);
            $purchase->forceFill(['reserved_amount' => 0])->save();
            $this->record($locked, BudgetMovement::TYPE_RELEASE, $cents, $userId, purchase: $purchase, intake: $intake);

            return $locked;
        });
    }

    /**
     * Consume `$amount` de lo libre (factura confirmada; la reserva propia ya se liberó).
     * Si lo supera lanza `BudgetExceededException`: 422 sin autorización, 403 si quien la pide no es DIR-01.
     * Con `$allowOver` y `$canAuthorize` consume igual y deja el exceso en auditoría y en el movimiento.
     *
     * @throws BudgetExceededException
     */
    public function consume(AnnualBudget $budget, string|float|int $amount, InvoiceIntake $intake, int $userId, ?Purchase $purchase = null, bool $allowOver = false, bool $canAuthorize = false): AnnualBudget
    {
        return DB::transaction(function () use ($budget, $amount, $intake, $userId, $purchase, $allowOver, $canAuthorize) {
            $locked = $this->lock($budget);
            $cents = $this->cents($amount);
            $free = $this->freeCents($locked);
            $over = max(0, $cents - $free);
            $notes = null;

            if ($over > 0) {
                if (! $allowOver || ! $canAuthorize) {
                    throw new BudgetExceededException($over, $free, $cents, $allowOver ? 403 : 422);
                }

                $notes = 'Exceso autorizado por usuario #'.$userId.': $'.$this->format($over).' sobre lo libre ($'.$this->format($free).').';
            }

            $this->apply($locked, availableDelta: -$cents, reservedDelta: 0, userId: $userId);
            $this->record($locked, BudgetMovement::TYPE_CONSUME, $cents, $userId, purchase: $purchase, intake: $intake, notes: $notes);

            if ($over > 0) {
                AuditLogger::record('over_budget_authorized', $intake, null, [
                    'annual_budget_id' => $locked->id,
                    'authorized_by' => $userId,
                    'over_by' => number_format($over / 100, 2, '.', ''),
                    'total' => number_format($cents / 100, 2, '.', ''),
                ]);
            }

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

    /** Consumido en centavos: `initial − available`. */
    public function consumedCents(AnnualBudget $budget): int
    {
        return $this->cents($budget->initial_amount) - $this->cents($budget->available_amount);
    }

    /** Gastable en centavos: `available − reserved`. */
    public function freeCents(AnnualBudget $budget): int
    {
        return $this->cents($budget->available_amount) - $this->cents($budget->reserved_amount);
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
