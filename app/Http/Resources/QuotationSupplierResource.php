<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Proveedor contactado por una cotización y su respuesta individual.
 */
class QuotationSupplierResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quotation_id' => $this->quotation_id,
            'supplier_id' => $this->supplier_id,
            'supplier' => new SupplierResource($this->whenLoaded('supplier')),
            'status' => $this->status,
            'offer_total' => $this->offer_total !== null ? (float) $this->offer_total : null,
            'notes' => $this->notes,
            'responded_at' => $this->responded_at?->toIso8601String(),
            'contacted_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
