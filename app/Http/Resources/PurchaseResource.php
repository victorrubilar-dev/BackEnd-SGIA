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
            'annual_budget_id' => $this->annual_budget_id,
            'reserved_amount' => $this->reserved_amount ?? '0.00',
            'quotation_reference' => $this->quotation_reference,
            'quotation_id' => $this->quotation_id,
            'purchase_request_id' => $this->resource->purchase_request_id,
            'purchase_request' => $this->whenLoaded('purchaseRequest', fn () => $this->resource->purchaseRequest?->only(['id', 'code', 'status'])),
            'order_number' => $this->resource->order_number,
            'has_order_file' => $this->resource->order_file_path !== null,
            'order_file_url' => $this->resource->order_file_path && $this->resource->purchase_request_id
                ? url("/api/purchase-requests/{$this->resource->purchase_request_id}/order/file")
                : null,
            'invoices' => InvoiceIntakeResource::collection($this->whenLoaded('invoiceIntakes')),
            'quotation_justification' => $this->resource->quotation_justification,
            'min_quotations' => $minQuotations = (int) config('sgia.min_quotations', 3),
            'responded_suppliers' => $this->when($this->resource->relationLoaded('quotation') && $this->resource->quotation !== null, fn () => $this->resource->quotation->respondedSuppliersCount()),
            'meets_min_quotations' => $this->when($this->resource->relationLoaded('quotation') && $this->resource->quotation !== null, fn () => $this->resource->quotation->respondedSuppliersCount() >= $minQuotations),
            'quotation' => new QuotationResource($this->whenLoaded('quotation')),
            'expected_at' => $this->expected_at?->toDateString(),
            'total' => $this->total !== null ? (float) $this->total : null,
            'notes' => $this->notes,
            'guide_number' => $this->guide_number,
            'invoice_number' => $this->invoice_number,
            'arrival_document' => $this->arrival_document,
            'arrival_document_url' => $this->arrival_document
                ? url("/api/purchases/{$this->id}/arrival-document")
                : null,
            'received_at' => $this->received_at?->toIso8601String(),
            'items' => PurchaseItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
