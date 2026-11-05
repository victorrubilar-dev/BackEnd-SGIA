<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceIntakeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'purchase_id' => $this->purchase_id,
            'purchase' => $this->whenLoaded('purchase', fn () => $this->purchase?->only(['code', 'status'])),
            'linked' => $this->purchase_id !== null,
            'annual_budget' => $this->whenLoaded('annualBudget', fn () => $this->annualBudget ? [
                'id' => $this->annualBudget->id,
                'available' => $this->annualBudget->available_amount,
                'reserved' => $this->annualBudget->reserved_amount,
            ] : null),
            'document_number' => $this->document_number,
            'status' => $this->status,
            'total_deducted' => $this->total_deducted,
            'has_file' => $this->pdf_path !== null,
            'raw_ocr_payload' => $this->raw_ocr_payload,
            'items' => InvoiceIntakeItemResource::collection($this->whenLoaded('items')),
            'received_at' => $this->received_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
