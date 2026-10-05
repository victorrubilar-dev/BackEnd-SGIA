<?php

namespace App\Http\Resources;

use App\Services\QuotationStateMachine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuotationResource extends JsonResource
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
            'code' => $this->code,
            'status' => $this->status,
            'allowed_transitions' => QuotationStateMachine::allowedFrom($this->status),
            'created_by' => $this->created_by,
            'creator' => new UserResource($this->whenLoaded('creator')),
            'notes' => $this->notes,
            'expires_at' => $this->expires_at?->toDateString(),
            'items' => QuotationItemResource::collection($this->whenLoaded('items')),
            'suppliers' => QuotationSupplierResource::collection($this->whenLoaded('suppliers')),
            'purchase_id' => $this->purchase_id,
            'purchase' => new PurchaseResource($this->whenLoaded('purchase')),
            'converted_at' => $this->converted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
