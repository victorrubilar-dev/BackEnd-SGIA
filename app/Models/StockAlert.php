<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
