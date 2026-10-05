<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $location = $this->whenLoaded('location') ?? ($this->relationLoaded('cajon') && $this->cajon ? $this->cajon->location : null);
        $cajon = $this->whenLoaded('cajon');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'barcode' => $this->barcode,
            'quantity' => (int) $this->quantity,
            'stock_minimo' => (int) $this->stock_minimo,
            'is_critical_stock' => $this->isCriticalStock(),
            'is_warning_stock' => $this->isWarningStock(),
            'supplier_id' => $this->supplier_id,
            'supplier' => new SupplierResource($this->whenLoaded('supplier')),
            'cajon_id' => $this->cajon_id,
            'cajon' => new CajonResource($cajon),
            'location_id' => $this->location_id ?? $this->cajon?->location_id,
            'location' => new LocationResource($location),
            'area' => $this->area,
            'photo_url' => $this->photo_url,
            'is_active' => (bool) $this->is_active,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
