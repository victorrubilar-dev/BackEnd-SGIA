<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AnnualBudgetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'career_id' => $this->career_id,
            'career' => new CareerResource($this->whenLoaded('career')),
            'year' => $this->year,
            'initial_amount' => $this->initial_amount,
            'available_amount' => $this->available_amount,
            'reserved_amount' => $this->reserved_amount,
            'free_amount' => number_format((float) $this->available_amount - (float) $this->reserved_amount, 2, '.', ''),
            'consumed_amount' => number_format((float) $this->initial_amount - (float) $this->available_amount, 2, '.', ''),
            'invoice_intakes' => InvoiceIntakeResource::collection($this->whenLoaded('invoiceIntakes')),
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
