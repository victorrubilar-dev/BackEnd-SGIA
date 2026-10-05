<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Proveedor contactado por una cotización, con su respuesta individual.
 */
class QuotationSupplier extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pendiente';
    public const STATUS_ACCEPTED = 'aceptada';
    public const STATUS_REJECTED = 'rechazada';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACCEPTED,
        self::STATUS_REJECTED,
    ];

    protected $fillable = [
        'quotation_id',
        'supplier_id',
        'status',
        'offer_total',
        'notes',
        'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'offer_total' => 'float',
            'responded_at' => 'datetime',
        ];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
