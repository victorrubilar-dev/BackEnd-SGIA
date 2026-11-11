<?php

namespace App\Http\Resources;

use App\Models\PurchaseRequestQuotation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Cotización subida (archivo + total). El archivo se descarga con `file_url` (Bearer, blob).
 *
 * @mixin PurchaseRequestQuotation
 */
class PurchaseRequestQuotationResource extends JsonResource
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
            'total' => (float) $this->total,
            'received_at' => $this->received_at->toIso8601String(),
            'uploaded_by' => $this->uploaded_by,
            'original_name' => $this->original_name,
            'file_url' => url("/api/purchase-requests/{$this->purchase_request_id}/quotations/{$this->id}/file"),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'purchase_request_item_id' => $item->purchase_request_item_id,
                'unit_price' => (float) $item->unit_price,
            ])->values()->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
