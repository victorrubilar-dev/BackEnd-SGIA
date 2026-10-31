<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ingreso de una factura leída por OCR: descuenta presupuesto e ingresa stock al confirmarse.
 */
class InvoiceIntake extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'borrador';

    public const STATUS_CONFIRMED = 'confirmado';

    public const STATUS_CANCELLED = 'anulado';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_CONFIRMED, self::STATUS_CANCELLED];

    protected $fillable = [
        'user_id', 'annual_budget_id', 'pdf_path', 'document_number',
        'total_deducted', 'raw_ocr_payload', 'status', 'received_at',
    ];

    protected function casts(): array
    {
        return [
            'total_deducted' => 'decimal:2',
            'raw_ocr_payload' => 'array',
            'received_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function annualBudget(): BelongsTo
    {
        return $this->belongsTo(AnnualBudget::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceIntakeItem::class);
    }
}
