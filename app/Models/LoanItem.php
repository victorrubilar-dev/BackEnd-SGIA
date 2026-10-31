<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class LoanItem extends Model
{
    use HasFactory;

    public const CONDITION_GOOD = 'bueno';

    public const CONDITION_REGULAR = 'regular';

    public const CONDITION_DAMAGED = 'danado';

    public const CONDITION_LOST = 'extraviado';

    protected $fillable = [
        'loan_id',
        'product_id',
        'name',
        'quantity',
        'delivered_quantity',
        'returned_quantity',
        'returned_at',
        'return_condition',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'delivered_quantity' => 'integer',
            'returned_quantity' => 'integer',
            'returned_at' => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /** Unidades físicas entregadas (productos `equipo` / `maqueta`). */
    public function units(): BelongsToMany
    {
        return $this->belongsToMany(EquipmentUnit::class, 'loan_item_units')
            ->withPivot(['returned_at', 'return_condition'])
            ->withTimestamps();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
