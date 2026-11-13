<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<int|string, mixed>
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
            'type' => $this->type,
            'quantity' => (int) $this->quantity,
            'quantity_in_repair' => (int) $this->quantity_in_repair,
            'quantity_written_off' => (int) $this->quantity_written_off,
            'estado' => $this->operationalStatus(),
            'stock_minimo' => (int) $this->stock_minimo,
            'is_critical_stock' => $this->isCriticalStock(),
            'is_warning_stock' => $this->isWarningStock(),
            'supplier_id' => $this->supplier_id,
            'supplier' => new SupplierResource($this->whenLoaded('supplier')),
            'cajon_id' => $this->cajon_id,
            'cajon' => new CajonResource($cajon),
            'location_id' => $this->location_id ?? ($this->resource->relationLoaded('cajon') ? $this->cajon?->location_id : null),
            'location' => new LocationResource($location),
            'area' => $this->area,
            'category_id' => $this->category_id,
            'career_id' => $this->career_id,
            'maintenance_priority' => $this->maintenance_priority,
            'requires_calibration' => (bool) $this->requires_calibration,
            'maintenance_frequency_months' => $this->maintenance_frequency_months,
            'reference_price' => $this->reference_price,
            'photo_url' => $this->photo_url,
            'is_active' => (bool) $this->is_active,
            // Solo con `?storeroom_id=`: equipos/maquetas cuentan unidades disponibles del pañol; los insumos, su total.
            $this->mergeWhen(isset($this->resource->available_in_storeroom), fn () => [
                'available_in_storeroom' => $this->resource->hasUnits()
                    ? (int) $this->resource->available_in_storeroom
                    : (int) $this->resource->quantity,
            ]),
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
