<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockAlertResource extends JsonResource
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
            'product_id' => $this->product_id,
            'product_name' => $this->product?->name,
            'product_barcode' => $this->product?->barcode,
            'alert_type' => $this->alert_type,
            'current_stock' => $this->current_stock,
            'stock_minimo' => $this->stock_minimo,
            'message' => $this->message,
            'is_resolved' => (bool) $this->is_resolved,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
