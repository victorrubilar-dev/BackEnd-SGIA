<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resumen de ejecución de un presupuesto anual (REQ-24).
 */
class BudgetSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $initial = (float) $this->initial_amount;
        $reserved = (float) $this->reserved_amount;
        $consumed = $initial - (float) $this->available_amount;

        return [
            'id' => $this->id,
            'career_id' => $this->career_id,
            'career' => new CareerResource($this->whenLoaded('career')),
            'year' => $this->year,
            'initial_amount' => $this->initial_amount,
            'reserved_amount' => $this->reserved_amount,
            'consumed_amount' => number_format($consumed, 2, '.', ''),
            'available_amount' => $this->available_amount,
            'free_amount' => number_format((float) $this->available_amount - $reserved, 2, '.', ''),
            'execution_percent' => $initial > 0 ? round(($consumed + $reserved) / $initial * 100, 2) : 0,
        ];
    }
}
