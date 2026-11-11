<?php

namespace App\Models;

use App\Services\PurchaseRequestStateMachine;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Solicitud de compra `SC-AAAA-NNNN` (nuevo flujo de compras): productos y cantidades que DIR-01
 * aprueba, envía a los proveedores de las áreas elegidas, cotiza (≥3 archivos), convierte en OC y factura.
 *
 * @property int $id
 * @property string $code
 * @property string $status
 * @property int $annual_budget_id
 * @property int|null $selected_quotation_id
 * @property int|null $created_by
 * @property int|null $approved_by
 * @property string|null $notes
 * @property Carbon|null $approved_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PurchaseRequest extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = PurchaseRequestStateMachine::STATUS_DRAFT;

    public const STATUS_APPROVED = PurchaseRequestStateMachine::STATUS_APPROVED;

    public const STATUS_SENT = PurchaseRequestStateMachine::STATUS_SENT;

    public const STATUS_QUOTED = PurchaseRequestStateMachine::STATUS_QUOTED;

    public const STATUS_ORDERED = PurchaseRequestStateMachine::STATUS_ORDERED;

    public const STATUS_INVOICED = PurchaseRequestStateMachine::STATUS_INVOICED;

    public const STATUS_CANCELLED = PurchaseRequestStateMachine::STATUS_CANCELLED;

    /** Cotizaciones subidas necesarias para poder elegir una. */
    public const MIN_QUOTATIONS = 3;

    protected $fillable = [
        'code',
        'status',
        'annual_budget_id',
        'created_by',
        'updated_by',
        'approved_by',
        'approved_at',
        'sent_at',
        'selected_quotation_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /** @return HasMany<PurchaseRequestItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseRequestItem::class);
    }

    /** @return HasMany<PurchaseRequestArea, $this> */
    public function areas(): HasMany
    {
        return $this->hasMany(PurchaseRequestArea::class);
    }

    /**
     * Proveedores contactados.
     *
     * @return HasMany<PurchaseRequestSupplier, $this>
     */
    public function suppliers(): HasMany
    {
        return $this->hasMany(PurchaseRequestSupplier::class);
    }

    /** @return HasMany<PurchaseRequestQuotation, $this> */
    public function quotations(): HasMany
    {
        return $this->hasMany(PurchaseRequestQuotation::class);
    }

    /** @return BelongsTo<PurchaseRequestQuotation, $this> */
    public function selectedQuotation(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequestQuotation::class, 'selected_quotation_id');
    }

    /**
     * OC vigente (no cancelada) emitida desde la solicitud.
     *
     * @return HasOne<Purchase, $this>
     */
    public function purchase(): HasOne
    {
        return $this->hasOne(Purchase::class)->where('status', '!=', Purchase::STATUS_CANCELLED)->latest('id');
    }

    /** @return HasMany<Purchase, $this> */
    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
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

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
