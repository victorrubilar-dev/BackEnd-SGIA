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
        $barcode = $this->sku ?? $this->barcode ?? ($this->relationLoaded('equipos') ? $this->equipos->first()?->codigo_barras : null);

        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'name' => $this->nombre,
            'sku' => $this->sku,
            'barcode' => $barcode,
            'tipo' => $this->tipo,
            'categoria_id' => $this->categoria_id,
            'categoria' => $this->relationLoaded('categoria') && $this->categoria ? [
                'id' => $this->categoria->id,
                'nombre' => $this->categoria->nombre,
            ] : null,
            'carrera_id' => $this->carrera_id,
            'carrera' => $this->relationLoaded('carrera') && $this->carrera ? [
                'id' => $this->carrera->id,
                'codigo' => $this->carrera->codigo,
                'nombre' => $this->carrera->nombre,
            ] : null,
            'stock_actual' => (int) ($this->stock_actual ?? $this->quantity ?? 0),
            'quantity' => (int) ($this->stock_actual ?? $this->quantity ?? 0),
            'stock_minimo' => (int) ($this->stock_minimo ?? 5),
            'is_critical_stock' => method_exists($this->resource, 'isCriticalStock') ? $this->isCriticalStock() : false,
            'is_warning_stock' => method_exists($this->resource, 'isWarningStock') ? $this->isWarningStock() : false,
            'precio_referencia' => $this->precio_referencia,
            'foto_url' => $this->foto_url,
            'is_active' => true,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
