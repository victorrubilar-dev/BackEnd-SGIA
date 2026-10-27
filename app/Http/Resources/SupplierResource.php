<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierResource extends JsonResource
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
            'rut' => $this->rut,
            'razon_social' => $this->razon_social,
            'name' => $this->razon_social,
            'contact_name' => $this->nombre_contacto,
            'nombre_contacto' => $this->nombre_contacto,
            'email' => $this->email,
            'phone' => $this->telefono,
            'telefono' => $this->telefono,
            'direccion' => $this->direccion,
            'category' => $this->category ?? null,
            'is_active' => (bool) $this->activo,
            'activo' => (bool) $this->activo,
            'products_count' => $this->whenCounted('products') ?? 0,
            'products' => ProductResource::collection($this->whenLoaded('products') ?? []),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
