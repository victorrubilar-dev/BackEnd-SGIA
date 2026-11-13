<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Presupuesto anual por carrera. `available_amount` = inicial − consumido (puede ser negativo solo con exceso autorizado).
 */
class AnnualBudget extends Model
{
    use HasFactory;

    protected $fillable = ['career_id', 'year', 'initial_amount', 'available_amount', 'reserved_amount', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'initial_amount' => 'decimal:2',
            'available_amount' => 'decimal:2',
            'reserved_amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Career, $this> */
    public function career(): BelongsTo
    {
        return $this->belongsTo(Career::class);
    }

    /** @return HasMany<InvoiceIntake, $this> */
    public function invoiceIntakes(): HasMany
    {
        return $this->hasMany(InvoiceIntake::class);
    }

    /** @return HasMany<BudgetMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(BudgetMovement::class);
    }

    /** @return HasMany<Purchase, $this> */
    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
