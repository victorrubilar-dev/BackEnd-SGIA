<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Cotización recibida por correo y subida a mano por DIR-01 (archivo + total + precio por ítem).
 *
 * @property int $id
 * @property int $purchase_request_id
 * @property int $supplier_id
 * @property string $file_path
 * @property string $total
 * @property string|null $original_name
 * @property int|null $uploaded_by
 * @property Carbon $received_at
 * @property Carbon|null $created_at
 */
class PurchaseRequestQuotation extends Model
{
    protected $fillable = [
        'purchase_request_id',
        'supplier_id',
        'file_path',
        'original_name',
        'total',
        'received_at',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'received_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PurchaseRequest, $this> */
    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return HasMany<PurchaseRequestQuotationItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseRequestQuotationItem::class, 'quotation_id');
    }
}
