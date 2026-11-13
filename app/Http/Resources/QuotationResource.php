<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuotationResource extends JsonResource
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
            'code' => $this->code,
            'career_id' => $this->career_id,
            'title' => $this->title,
            'status' => $this->status,
            // Historial de solo lectura: ya no hay transiciones disponibles por la API.
            'allowed_transitions' => [],
            'created_by' => $this->created_by,
            'creator' => new UserResource($this->whenLoaded('creator')),
            'notes' => $this->notes,
            'min_quotations' => $minQuotations = (int) config('sgia.min_quotations', 3),
            'responded_suppliers' => $respondedSuppliers = $this->resource->respondedSuppliersCount(),
            'meets_min_quotations' => $respondedSuppliers >= $minQuotations,
            'expires_at' => $this->expires_at?->toDateString(),
            'items' => QuotationItemResource::collection($this->whenLoaded('items')),
            'suppliers' => QuotationSupplierResource::collection($this->whenLoaded('suppliers')),
            'purchase_id' => $this->purchase_id,
            'purchase_code' => $this->whenLoaded('purchase', fn () => $this->resource->purchase?->code),
            'purchase' => new PurchaseResource($this->whenLoaded('purchase')),
            'converted_at' => $this->converted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
