<?php

namespace App\Http\Resources;

use App\Services\LoanStateMachine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoanResource extends JsonResource
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
            'type' => $this->type,
            // Estado explícito para que el cliente aplique su estilo visual (REQ-11)
            'status' => $this->status,
            'status_label' => LoanStateMachine::labelFor((string) $this->status),
            'is_pending' => $this->isPending(),
            'is_in_progress' => $this->isInProcess(),
            'is_processed' => $this->isProcessed(),
            'is_rejected' => $this->isRejected(),
            'allowed_transitions' => LoanStateMachine::allowedFrom((string) $this->status),
            'requested_by' => $this->requested_by,
            'requester' => new UserResource($this->whenLoaded('requester')),
            'borrower_name' => $this->borrower_name,
            'borrower_document' => $this->borrower_document,
            'subject' => $this->subject,
            'room' => $this->room,
            'loan_date' => $this->loan_date?->toDateString(),
            'time_block' => $this->time_block,
            'rejection_reason' => $this->rejection_reason,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'processed_by' => $this->processed_by,
            'processed_at' => $this->processed_at?->toIso8601String(),
            'notes' => $this->notes,
            'items' => LoanItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
