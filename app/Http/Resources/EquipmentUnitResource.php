<?php

namespace App\Http\Resources;

use App\Models\EquipmentUnit;
use App\Models\Loan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EquipmentUnit
 */
class EquipmentUnitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $cajonLoaded = $this->relationLoaded('cajon') && $this->cajon !== null;
        $locationLoaded = $cajonLoaded && $this->cajon->relationLoaded('location');
        $storeroomLoaded = $locationLoaded && $this->cajon->location->relationLoaded('storeroom');

        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product' => new ProductResource($this->whenLoaded('product')),
            'code' => $this->code,
            'serial_number' => $this->serial_number,
            'status' => $this->status,
            'cajon_id' => $this->cajon_id,
            'cajon' => new CajonResource($this->whenLoaded('cajon')),
            'location' => $this->when($locationLoaded, fn () => new LocationResource($this->cajon->location)),
            'storeroom' => $this->when($storeroomLoaded, fn () => new StoreroomResource($this->cajon->location->storeroom)),
            'active_loan' => $this->when($this->relationLoaded('loanItems'), fn () => $this->activeLoan()),
            'last_maintenance_at' => $this->last_maintenance_at?->toIso8601String(),
            'next_maintenance_at' => $this->next_maintenance_at?->toIso8601String(),
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Préstamo vigente de la unidad (requiere `loanItems.loan` cargado).
     *
     * @return array<string, mixed>|null
     */
    private function activeLoan(): ?array
    {
        $item = $this->loanItems->first(
            fn ($item) => $item->pivot->returned_at === null
                && in_array($item->loan?->status, [Loan::STATUS_IN_PROGRESS, Loan::STATUS_PROCESSED], true)
        );

        return $item ? [
            'id' => $item->loan->id,
            'code' => $item->loan->code,
            'status' => $item->loan->status,
            'borrower_name' => $item->loan->borrower_name,
            'due_date' => ($item->loan->due_date ?? $item->loan->loan_date)?->toDateString(),
        ] : null;
    }
}
