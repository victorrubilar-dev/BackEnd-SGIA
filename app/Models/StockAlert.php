<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockAlert extends Model
{
    use HasFactory;

    public const TYPE_WARNING = 'warning';

    public const TYPE_CRITICAL = 'critical';

    protected $fillable = [
        'product_id',
        'alert_type',
        'current_stock',
        'stock_minimo',
        'message',
        'is_resolved',
    ];

    protected function casts(): array
    {
        return [
            'current_stock' => 'integer',
            'stock_minimo' => 'integer',
            'is_resolved' => 'boolean',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<StockAlertDismissal, $this> */
    public function dismissals(): HasMany
    {
        return $this->hasMany(StockAlertDismissal::class);
    }

    /**
     * Sin las alertas que el usuario quitó de su campana (docs/exportaciones-y-campana.md).
     *
     * @param  Builder<StockAlert>  $query
     */
    public function scopeNotDismissedBy(Builder $query, int $userId): void
    {
        $query->whereDoesntHave('dismissals', fn (Builder $q) => $q->where('user_id', $userId));
    }
}
