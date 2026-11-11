<?php

namespace App\Http\Resources;

use App\Models\PurchaseRequestSupplier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Proveedor contactado y estado de su correo.
 *
 * @mixin PurchaseRequestSupplier
 */
class PurchaseRequestSupplierResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_request_id' => $this->purchase_request_id,
            'supplier_id' => $this->supplier_id,
            'supplier' => new SupplierResource($this->whenLoaded('supplier')),
            'email_status' => $this->email_sent_at !== null ? 'enviado' : ($this->email_error !== null ? 'fallido' : 'pendiente'),
            'email_sent_at' => $this->email_sent_at?->toIso8601String(),
            'email_error' => $this->email_error,
        ];
    }
}
