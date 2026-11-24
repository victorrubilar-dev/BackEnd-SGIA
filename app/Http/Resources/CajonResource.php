<?php

namespace App\Http\Resources;

use App\Models\Cajon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Cajon
 */
class CajonResource extends JsonResource
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
            'codigo' => $this->codigo,
            'barcode' => $this->barcode,
            'descripcion' => $this->descripcion,
            'location_id' => $this->location_id,
            'location' => new LocationResource($this->whenLoaded('location')),
            // REQ-22: estante del plano donde está el cajón.
            'estante_id' => $this->estante_id,
            'estante' => $this->whenLoaded('estante', fn () => $this->estante ? ['id' => $this->estante->id, 'codigo' => $this->estante->codigo] : null),
            // Cajón marcado como lleno (docs/cajones-llenos.md).
            'lleno' => (bool) $this->lleno,
            'products_count' => $this->whenCounted('products'),
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
