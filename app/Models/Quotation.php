<?php

namespace App\Models;

use App\Services\QuotationStateMachine;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Quotation extends Model
{
    use HasFactory;

    public const STATUS_PENDING = QuotationStateMachine::STATUS_PENDING;
    public const STATUS_ACCEPTED = QuotationStateMachine::STATUS_ACCEPTED;
    public const STATUS_REJECTED = QuotationStateMachine::STATUS_REJECTED;
    public const STATUS_CONVERTED = QuotationStateMachine::STATUS_CONVERTED;

    public const STATUSES = QuotationStateMachine::STATUSES;

    protected $fillable = [
        'code',
        'created_by',
        'status',
        'notes',
        'expires_at',
        'purchase_id',
        'converted_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'date',
            'converted_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
    }

    /**
     * Proveedores contactados por esta cotización (con su respuesta individual).
     */
    public function suppliers(): HasMany
    {
        return $this->hasMany(QuotationSupplier::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function isConverted(): bool
    {
        return $this->status === self::STATUS_CONVERTED;
    }

    /**
     * Generate a unique quotation code (e.g. COT-2026-A1B2C3).
     */
    public static function generateUniqueCode(): string
    {
        do {
            $code = 'COT-' . date('Y') . '-' . strtoupper(Str::random(6));
        } while (static::where('code', $code)->exists());

        return $code;
    }
}
