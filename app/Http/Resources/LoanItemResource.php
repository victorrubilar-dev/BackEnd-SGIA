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
        $product = $this->equipo?->producto ?? ($this->relationLoaded('product') ? $this->product : null);
        $estante = $this->equipo?->estante;
        $sala = $estante?->sala;

        return [
            'id' => $this->id,
            'loan_id' => $this->prestamo_id ?? $this->loan_id,
            'product_id' => $this->product_id,
            'product' => $product ? new ProductResource($product) : null,
            'name' => $this->name,
            'quantity' => (int) $this->quantity,
            'available_stock' => $product !== null ? (int) $product->stock_actual : null,
            'cajon' => $estante !== null
                ? [
                    'id' => $estante->id,
                    'codigo' => $estante->codigo_barras,
                    'descripcion' => $estante->nombre,
                ]
                : null,
            'location' => $sala !== null
                ? [
                    'id' => $sala->id,
                    'nombre' => $sala->nombre_codigo,
                    'tipo' => 'sala',
                    'sala' => $sala->nombre_codigo,
                    'cajon' => $estante?->codigo_barras,
                    'descripcion' => $sala->nombre_codigo,
                ]
                : null,
        ];
    }
}
