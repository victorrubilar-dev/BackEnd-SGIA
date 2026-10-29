<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'returned_at',
        'return_condition',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'returned_at' => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
