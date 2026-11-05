<?php

namespace App\Http\Resources;

use App\Models\InvoiceIntake;
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
            'annual_budget_id' => $this->annual_budget_id,
            'purchase_id' => $this->purchase_id,
            'detected_career_id' => $this->raw_ocr_payload['career_id'] ?? null,
            'needs_budget' => $this->resource->status === InvoiceIntake::STATUS_DRAFT && $this->resource->annual_budget_id === null,
            'annual_budget' => new AnnualBudgetResource($this->whenLoaded('annualBudget')),
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
