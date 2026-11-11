<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Proveedor contactado por una solicitud, con el resultado del envío del correo.
 *
 * @property int $id
 * @property int $purchase_request_id
 * @property int $supplier_id
 * @property Supplier|null $supplier
 * @property Carbon|null $email_sent_at
 * @property string|null $email_error
 */
class PurchaseRequestSupplier extends Model
{
    protected $fillable = ['purchase_request_id', 'supplier_id', 'email_sent_at', 'email_error'];

    protected function casts(): array
    {
        return ['email_sent_at' => 'datetime'];
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
}
