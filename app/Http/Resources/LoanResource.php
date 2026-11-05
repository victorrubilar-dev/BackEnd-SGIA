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
            'student_id' => $this->student_id,
            'student' => new StudentResource($this->whenLoaded('student')),
            'subject' => $this->subject,
            'career_id' => $this->resource->career_id,
            'career' => new CareerResource($this->whenLoaded('career')),
            'storeroom_id' => $this->resource->storeroom_id,
            'storeroom' => $this->when($this->resource->relationLoaded('storeroom') && $this->resource->storeroom !== null, fn () => [
                'id' => $this->resource->storeroom->id,
                'name' => $this->resource->storeroom->name,
                'code' => $this->resource->storeroom->code,
            ]),
            'location_id' => $this->resource->location_id,
            'location' => $this->when($this->resource->relationLoaded('location') && $this->resource->location !== null, fn () => [
                'id' => $this->resource->location->id,
                'nombre' => $this->resource->location->nombre ?? $this->resource->location->sala,
                'tipo' => $this->resource->location->tipo,
            ]),
            // Horario de la actividad (HH:MM); `time_block` conserva el texto para clientes antiguos.
            'start_time' => $this->resource->start_time ? substr((string) $this->resource->start_time, 0, 5) : null,
            'end_time' => $this->resource->end_time ? substr((string) $this->resource->end_time, 0, 5) : null,
            // Profesor que solicita: siempre el usuario autenticado que creó la solicitud (solo lectura).
            'teacher' => $this->when($this->resource->relationLoaded('requester') && $this->resource->requester !== null, fn () => [
                'id' => $this->resource->requester->id,
                'name' => $this->resource->requester->name,
            ]),
            'room' => $this->room,
            'loan_date' => $this->loan_date?->toDateString(),
            'due_date' => ($this->due_date ?? $this->loan_date)?->toDateString(),
            'returned_at' => $this->returned_at?->toIso8601String(),
            'returned_by' => $this->returned_by,
            'return_notes' => $this->return_notes,
            'is_returned' => $this->returned_at !== null,
            'is_overdue' => $this->isOverdue(),
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
