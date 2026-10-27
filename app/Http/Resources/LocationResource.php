<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LocationResource extends JsonResource
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
            'nombre' => $this->nombre_codigo ?? $this->nombre,
            'nombre_codigo' => $this->nombre_codigo ?? $this->nombre,
            'edificio' => $this->edificio,
            'piso' => $this->piso,
            'panol_id' => $this->panol_id,
            'panol' => $this->relationLoaded('panol') && $this->panol ? [
                'id' => $this->panol->id,
                'nombre' => $this->panol->nombre,
            ] : null,
            'estantes_count' => $this->estantes_count ?? ($this->relationLoaded('estantes') ? $this->estantes->count() : null),
            'cajones_count' => $this->estantes_count ?? ($this->relationLoaded('estantes') ? $this->estantes->count() : null),
            'estantes' => CajonResource::collection($this->whenLoaded('estantes')),
            'cajones' => CajonResource::collection($this->whenLoaded('estantes')),
        ];
    }
}
