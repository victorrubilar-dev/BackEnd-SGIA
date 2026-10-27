<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            'codigo' => $this->codigo_barras ?? $this->codigo ?? $this->nombre,
            'codigo_barras' => $this->codigo_barras ?? $this->codigo,
            'nombre' => $this->nombre,
            'descripcion' => $this->nombre,
            'location_id' => $this->sala_id ?? $this->location_id,
            'sala_id' => $this->sala_id ?? $this->location_id,
            'location' => new LocationResource($this->whenLoaded('sala') ?? $this->whenLoaded('location')),
            'sala' => new LocationResource($this->whenLoaded('sala') ?? $this->whenLoaded('location')),
            'products_count' => $this->whenCounted('equipos') ?? $this->whenCounted('products'),
            'equipos_count' => $this->whenCounted('equipos'),
        ];
    }
}
