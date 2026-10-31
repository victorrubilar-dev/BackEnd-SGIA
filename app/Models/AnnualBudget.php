<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Presupuesto anual por carrera. `available_amount` nunca es negativo (CHECK en BD).
 */
class AnnualBudget extends Model
{
    use HasFactory;

    protected $fillable = ['career_id', 'year', 'initial_amount', 'available_amount', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'initial_amount' => 'decimal:2',
            'available_amount' => 'decimal:2',
        ];
    }

    public function career(): BelongsTo
    {
        return $this->belongsTo(Career::class);
    }

    public function invoiceIntakes(): HasMany
    {
        return $this->hasMany(InvoiceIntake::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
