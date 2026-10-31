<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Precio ofertado por un proveedor para un ítem de la cotización.
 */
class QuotationOfferItem extends Model
{
    use HasFactory;

    protected $fillable = ['quotation_supplier_id', 'product_id', 'requested_quantity', 'offered_unit_price'];

    protected function casts(): array
    {
        return [
            'requested_quantity' => 'integer',
            'offered_unit_price' => 'decimal:2',
        ];
    }

    public function quotationSupplier(): BelongsTo
    {
        return $this->belongsTo(QuotationSupplier::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
