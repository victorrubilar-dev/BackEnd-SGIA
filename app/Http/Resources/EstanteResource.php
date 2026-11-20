<?php

namespace App\Http\Resources;

use App\Models\Cajon;
use App\Models\Estante;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Estante del plano (REQ-22). Espera `Estante::loadMapSummary()` (o las mismas cargas en la consulta).
 *
 * @mixin Estante
 */
class EstanteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $cajones = $this->relationLoaded('cajones') ? $this->cajones : collect();

        return [
            'id' => $this->id,
            'location_id' => $this->location_id,
            'zona_id' => $this->zona_id,
            'codigo' => $this->codigo,
            'nombre' => $this->nombre,
            'descripcion' => $this->descripcion,
            'x' => $this->x,
            'y' => $this->y,
            'width' => $this->width,
            'depth' => $this->depth,
            'rotation' => $this->rotation,
            'cajones' => $cajones->map(fn (Cajon $cajon) => [
                'id' => $cajon->id,
                'codigo' => $cajon->codigo,
                'barcode' => $cajon->barcode,
                'products_count' => (int) ($cajon->products_count ?? 0),
                'units_count' => (int) ($cajon->units_count ?? 0),
            ])->values()->all(),
            'items_count' => (int) $cajones->sum(fn (Cajon $cajon) => (int) ($cajon->products_count ?? 0) + (int) ($cajon->units_count ?? 0)),
            'has_critical_stock' => (bool) ($this->has_critical_stock ?? false),
        ];
    }
}
