<?php

namespace App\Http\Resources;

use App\Services\PurchaseStateMachine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseResource extends JsonResource
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
            'allowed_transitions' => PurchaseStateMachine::allowedFrom($this->status),
            'supplier_id' => $this->supplier_id,
            'supplier' => new SupplierResource($this->whenLoaded('supplier')),
            'created_by' => $this->created_by,
            'creator' => new UserResource($this->whenLoaded('creator')),
            'quotation_reference' => $this->quotation_reference,
            'quotation_id' => $this->quotation_id,
            'quotation' => new QuotationResource($this->whenLoaded('quotation')),
            'expected_at' => $this->expected_at?->toDateString(),
            'total' => $this->total !== null ? (float) $this->total : null,
            'notes' => $this->notes,
            'guide_number' => $this->guide_number,
            'invoice_number' => $this->invoice_number,
            'arrival_document' => $this->arrival_document,
            'received_at' => $this->received_at?->toIso8601String(),
            'items' => PurchaseItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
