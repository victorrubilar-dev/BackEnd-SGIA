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
            'nombre' => $this->nombre ?? $this->sala,
            'tipo' => $this->tipo ?? 'sala',
            'sala' => $this->sala ?? $this->nombre,
            'cajon' => $this->cajon,
            'descripcion' => $this->descripcion,
            'storeroom_id' => $this->storeroom_id,
            'building' => $this->building,
            'floor' => $this->floor,
            'cajones_count' => $this->whenCounted('cajones'),
            'cajones' => CajonResource::collection($this->whenLoaded('cajones')),
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
