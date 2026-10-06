<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

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
            // URL pública del adjunto (documento o imagen del reporte).
            'attachment_url' => $this->attachment
                ? Storage::disk('public')->url($this->attachment)
                : null,
            'reported_by' => $this->reported_by,
            'reporter' => new UserResource($this->whenLoaded('reporter')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
