<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Movimiento inmutable del ledger de un presupuesto anual. `amount` siempre es positivo;
 * el signo lo da el tipo.
 */
class BudgetMovement extends Model
{
    public const TYPE_INITIAL = 'asignacion_inicial';

    public const TYPE_ADJUSTMENT = 'ajuste';

    public const TYPE_RESERVE = 'reserva';

    public const TYPE_RELEASE = 'liberacion_reserva';

    public const TYPE_CONSUME = 'consumo';

    public const TYPE_RESTORE = 'reposicion';

    public const TYPES = [
        self::TYPE_INITIAL, self::TYPE_ADJUSTMENT, self::TYPE_RESERVE,
        self::TYPE_RELEASE, self::TYPE_CONSUME, self::TYPE_RESTORE,
    ];

    public const UPDATED_AT = null;

    protected $fillable = ['annual_budget_id', 'type', 'amount', 'purchase_id', 'invoice_intake_id', 'user_id', 'notes'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AnnualBudget, $this> */
    public function annualBudget(): BelongsTo
    {
        return $this->belongsTo(AnnualBudget::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Purchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    /** @return BelongsTo<InvoiceIntake, $this> */
    public function invoiceIntake(): BelongsTo
    {
        return $this->belongsTo(InvoiceIntake::class);
    }
}
