<?php

namespace App\Http\Resources;

use App\Models\IncidentReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IncidentReportResource extends JsonResource
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
            'product_id' => $this->product_id,
            'title' => $this->title,
            'description' => $this->description,
            'severity' => $this->severity,
            'severity_label' => $this->severityLabel(),
            'status' => $this->status,
            'allowed_transitions' => $this->allowedTransitions(),
            'product' => new ProductResource($this->whenLoaded('product')),
            'equipment_unit_id' => $this->equipment_unit_id,
            'equipment_unit' => new EquipmentUnitResource($this->whenLoaded('equipmentUnit')),
            'request_type' => $this->request_type,
            'has_evidence_photo' => $this->evidence_photo_path !== null,
            'attachment' => $this->attachment,
            'attachment_original_name' => $this->attachment_original_name,
            // Descarga autenticada: el adjunto vive en disco privado.
            'attachment_url' => $this->attachment
                ? url("/api/incident-reports/{$this->id}/attachment")
                : null,
            'is_legacy' => $this->resource->isLegacy(),
            'teacher_name' => $this->resource->teacher_name,
            'subject' => $this->resource->subject,
            'report_date' => $this->resource->report_date?->toDateString(),
            'location_id' => $this->resource->location_id,
            'location' => new LocationResource($this->whenLoaded('location')),
            'problems' => $this->resource->problems === null ? null : array_map(fn (array $p) => $p + [
                'label' => IncidentReport::PROBLEM_LABELS[$p['type']] ?? $p['type'],
            ], $this->resource->problems),
            'suggestion' => $this->resource->suggestion,
            'has_signature' => $this->resource->signature_path !== null,
            'signature_url' => $this->resource->signature_path ? url("/api/incident-reports/{$this->resource->id}/signature") : null,
            'signed_at' => $this->resource->signed_at?->toIso8601String(),
            'pdf_url' => $this->resource->problems !== null ? url("/api/incident-reports/{$this->resource->id}/pdf") : null,
            'reported_by' => $this->reported_by,
            'reporter' => new UserResource($this->whenLoaded('reporter')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
