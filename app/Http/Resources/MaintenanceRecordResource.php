<?php

namespace App\Http\Resources;

use App\Models\MaintenanceRecord;
use App\Services\MaintenanceRecordStateMachine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MaintenanceRecord
 */
class MaintenanceRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'equipment_unit_id' => $this->equipment_unit_id,
            'equipment_unit' => new EquipmentUnitResource($this->whenLoaded('equipmentUnit')),
            'maintenance_type' => $this->maintenance_type,
            'execution_mode' => $this->execution_mode,
            'external_supplier_id' => $this->external_supplier_id,
            'external_supplier' => new SupplierResource($this->whenLoaded('externalSupplier')),
            'incident_report_id' => $this->incident_report_id,
            'incident_report' => new IncidentReportResource($this->whenLoaded('incidentReport')),
            'responsible_id' => $this->responsible_id,
            'responsible' => new UserResource($this->whenLoaded('responsible')),
            'rev_a_approved' => (bool) $this->rev_a_approved,
            'rev_b_approved' => (bool) $this->rev_b_approved,
            'rev_c_approved' => $this->rev_c_approved,
            'pfg_approved' => (bool) $this->pfg_approved,
            'checklist_complete' => $this->checklistComplete(),
            'requires_additional_maintenance' => (bool) $this->requires_additional_maintenance,
            'applies_recf' => (bool) $this->applies_recf,
            'repair_cost' => $this->repair_cost,
            'spare_parts_used' => $this->spare_parts_used,
            'technical_notes' => $this->technical_notes,
            'status' => $this->status,
            'result' => $this->result,
            'allowed_transitions' => MaintenanceRecordStateMachine::allowedFrom((string) $this->status),
            'documents' => MaintenanceDocumentResource::collection($this->whenLoaded('documents')),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
