<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

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
            'delivered_quantity' => (int) $this->delivered_quantity,
            'returned_quantity' => (int) $this->returned_quantity,
            'units' => $this->when($this->relationLoaded('units'), fn () => $this->units->map(fn ($unit) => [
                'id' => $unit->id,
                'code' => $unit->code,
                'serial_number' => $unit->serial_number,
                'status' => $unit->status,
                'returned_at' => $unit->pivot->returned_at ? Carbon::parse($unit->pivot->returned_at)->toIso8601String() : null,
                'return_condition' => $unit->pivot->return_condition,
            ])->values()),
            'is_returned' => $this->returned_at !== null,
            'returned_at' => $this->returned_at?->toIso8601String(),
            'return_condition' => $this->return_condition,
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
