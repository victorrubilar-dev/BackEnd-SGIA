<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoanItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $product = $this->relationLoaded('product') ? $this->product : null;
        $cajon = $product?->relationLoaded('cajon') ? $product->cajon : null;
        $location = $product?->relationLoaded('location') ? $product->location : null;

        if ($location === null && $cajon !== null && $cajon->relationLoaded('location')) {
            $location = $cajon->location;
        }

        return [
            'id' => $this->id,
            'loan_id' => $this->loan_id,
            'product_id' => $this->product_id,
            'product' => new ProductResource($this->whenLoaded('product')),
            'name' => $this->name,
            'quantity' => (int) $this->quantity,
            // Disponible y ubicación física se incluyen cuando se precarga el
            // producto (pañol: GET /api/loans/pending, REQ-10).
            'available_stock' => $product !== null ? (int) $product->quantity : null,
            'cajon' => $cajon !== null
                ? [
                    'id' => $cajon->id,
                    'codigo' => $cajon->codigo,
                    'descripcion' => $cajon->descripcion,
                ]
                : null,
            'location' => $location !== null
                ? [
                    'id' => $location->id,
                    'nombre' => $location->nombre ?? $location->sala,
                    'tipo' => $location->tipo ?? 'sala',
                    'sala' => $location->sala ?? $location->nombre,
                    'cajon' => $cajon?->codigo ?? $location->cajon,
                    'descripcion' => $location->descripcion,
                ]
                : null,
        ];
    }
}
