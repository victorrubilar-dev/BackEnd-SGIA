<?php

namespace App\Http\Resources;

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
            'attachment' => $this->attachment,
            'attachment_original_name' => $this->attachment_original_name,
            // Descarga autenticada: el adjunto vive en disco privado.
            'attachment_url' => $this->attachment
                ? url("/api/incident-reports/{$this->id}/attachment")
                : null,
            'reported_by' => $this->reported_by,
            'reporter' => new UserResource($this->whenLoaded('reporter')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
