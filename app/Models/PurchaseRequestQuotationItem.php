<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Precio unitario cotizado para un ítem de la solicitud.
 *
 * @property int $purchase_request_item_id
 * @property string $unit_price
 */
class PurchaseRequestQuotationItem extends Model
{
    protected $fillable = ['quotation_id', 'purchase_request_item_id', 'unit_price'];

    protected function casts(): array
    {
        return ['unit_price' => 'decimal:2'];
    }

    /** @return BelongsTo<PurchaseRequestQuotation, $this> */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequestQuotation::class, 'quotation_id');
    }

    /** @return BelongsTo<PurchaseRequestItem, $this> */
    public function purchaseRequestItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequestItem::class);
    }
}
