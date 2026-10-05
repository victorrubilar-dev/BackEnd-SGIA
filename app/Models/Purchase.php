<?php

namespace App\Models;

use App\Services\PurchaseStateMachine;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Purchase extends Model
{
    use HasFactory;

    public const STATUS_PENDING = PurchaseStateMachine::STATUS_PENDING;
    public const STATUS_IN_TRANSIT = PurchaseStateMachine::STATUS_IN_TRANSIT;
    public const STATUS_COMPLETED = PurchaseStateMachine::STATUS_COMPLETED;

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_IN_TRANSIT,
        self::STATUS_COMPLETED,
    ];

    protected $fillable = [
        'code',
        'supplier_id',
        'created_by',
        'quotation_id',
        'status',
        'quotation_reference',
        'expected_at',
        'total',
        'notes',
        'guide_number',
        'invoice_number',
        'arrival_document',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'expected_at' => 'date',
            'total' => 'float',
            'received_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Cotización aceptada a partir de la cual se generó esta orden (REQ-07 -> REQ-08).
     */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Generate a unique purchase order code (e.g. OC-2026-A1B2C3).
     */
    public static function generateUniqueCode(): string
    {
        do {
            $code = 'OC-' . date('Y') . '-' . strtoupper(Str::random(6));
        } while (static::where('code', $code)->exists());

        return $code;
    }
}
