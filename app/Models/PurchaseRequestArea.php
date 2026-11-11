<?php

namespace App\Models;

use App\Enums\SupplierArea;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Área a la que se envió la solicitud (trazabilidad).
 *
 * @property SupplierArea $area
 */
class PurchaseRequestArea extends Model
{
    protected $fillable = ['purchase_request_id', 'area'];

    protected function casts(): array
    {
        return ['area' => SupplierArea::class];
    }

    /** @return BelongsTo<PurchaseRequest, $this> */
    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }
}
