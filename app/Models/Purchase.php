<?php

namespace App\Models;

use App\Services\PurchaseStateMachine;
use Illuminate\Database\Eloquent\Builder;
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

    public const STATUS_CANCELLED = PurchaseStateMachine::STATUS_CANCELLED;

    public const STATUSES = PurchaseStateMachine::STATUSES;

    protected $fillable = [
        'code',
        'supplier_id',
        'created_by',
        'quotation_id',
        'purchase_request_id',
        'order_number',
        'order_file_path',
        'status',
        'quotation_reference',
        'quotation_justification',
        'annual_budget_id',
        'expected_at',
        'total',
        'reserved_amount',
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
            'reserved_amount' => 'decimal:2',
            'received_at' => 'datetime',
        ];
    }

    /**
     * Órdenes que aún esperan factura: no canceladas y sin otro ingreso vigente.
     *
     * @param  Builder<Purchase>  $query
     * @return Builder<Purchase>
     */
    public function scopeLinkable(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_IN_TRANSIT, self::STATUS_COMPLETED])
            ->whereNotIn('id', InvoiceIntake::query()->whereNotNull('purchase_id')->where('status', '!=', InvoiceIntake::STATUS_CANCELLED)->select('purchase_id'));
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<AnnualBudget, $this> */
    public function annualBudget(): BelongsTo
    {
        return $this->belongsTo(AnnualBudget::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Cotización aceptada a partir de la cual se generó esta orden (REQ-07 -> REQ-08).
     *
     * @return BelongsTo<Quotation, $this>
     */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    /** @return HasMany<PurchaseItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    /**
     * Solicitud de compra desde la que se subió esta OC (null en compras históricas).
     *
     * @return BelongsTo<PurchaseRequest, $this>
     */
    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    /** @return HasMany<InvoiceIntake, $this> */
    public function invoiceIntakes(): HasMany
    {
        return $this->hasMany(InvoiceIntake::class);
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
            $code = 'OC-'.date('Y').'-'.strtoupper(Str::random(6));
        } while (static::where('code', $code)->exists());

        return $code;
    }
}
