<?php

namespace App\Http\Resources;

use App\Models\Zona;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Zona
 */
class ZonaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'location_id' => $this->location_id,
            'nombre' => $this->nombre,
            'x' => $this->x,
            'y' => $this->y,
            'width' => $this->width,
            'depth' => $this->depth,
        ];
    }
}
