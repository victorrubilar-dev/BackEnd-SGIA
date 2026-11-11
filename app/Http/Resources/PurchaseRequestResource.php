<?php

namespace App\Http\Resources;

use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestArea;
use App\Services\PurchaseRequestStateMachine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PurchaseRequest
 */
class PurchaseRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PurchaseRequest $model */
        $model = $this->resource;
        $status = $model->status;
        $beforeSelection = in_array($status, [PurchaseRequest::STATUS_SENT, PurchaseRequest::STATUS_QUOTED], true) && $model->selected_quotation_id === null;

        return [
            'id' => $model->id,
            'code' => $model->code,
            'status' => $status,
            'status_label' => PurchaseRequestStateMachine::label($status),
            'annual_budget_id' => $model->annual_budget_id,
            'annual_budget' => new AnnualBudgetResource($this->whenLoaded('annualBudget')),
            'created_by' => $model->created_by,
            'creator' => new UserResource($this->whenLoaded('creator')),
            'approved_by' => $model->approved_by,
            'approver' => new UserResource($this->whenLoaded('approver')),
            'approved_at' => $model->approved_at?->toIso8601String(),
            'sent_at' => $model->sent_at?->toIso8601String(),
            'selected_quotation_id' => $model->selected_quotation_id,
            'notes' => $model->notes,
            'min_quotations' => PurchaseRequest::MIN_QUOTATIONS,
            'quotations_count' => $this->whenCounted('quotations'),
            // Acciones disponibles según el estado (la API valida igual; el rol lo decide el cliente: solo DIR-01 escribe).
            'can_edit' => $status === PurchaseRequest::STATUS_DRAFT,
            'can_approve' => $status === PurchaseRequest::STATUS_DRAFT,
            'can_send' => $status === PurchaseRequest::STATUS_APPROVED,
            'can_resend' => in_array($status, [PurchaseRequest::STATUS_SENT, PurchaseRequest::STATUS_QUOTED], true),
            'can_upload_quotation' => in_array($status, [PurchaseRequest::STATUS_SENT, PurchaseRequest::STATUS_QUOTED], true),
            'can_remove_quotation' => $beforeSelection,
            'can_select_quotation' => $status === PurchaseRequest::STATUS_QUOTED,
            'can_issue_order' => $status === PurchaseRequest::STATUS_QUOTED && $model->selected_quotation_id !== null,
            'can_cancel' => in_array($status, PurchaseRequestStateMachine::CANCELLABLE, true),
            'items' => PurchaseRequestItemResource::collection($this->whenLoaded('items')),
            'areas' => $this->whenLoaded('areas', fn () => $model->areas->map(fn (PurchaseRequestArea $a) => $a->area->value)->sort()->values()->all()),
            'suppliers' => PurchaseRequestSupplierResource::collection($this->whenLoaded('suppliers')),
            'quotations' => PurchaseRequestQuotationResource::collection($this->whenLoaded('quotations')),
            'order' => $this->whenLoaded('purchase', fn () => $model->purchase ? new PurchaseResource($model->purchase) : null),
            'created_at' => $model->created_at?->toIso8601String(),
            'updated_at' => $model->updated_at?->toIso8601String(),
        ];
    }
}
