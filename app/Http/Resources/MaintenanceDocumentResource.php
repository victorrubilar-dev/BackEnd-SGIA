<?php

namespace App\Http\Resources;

use App\Models\MaintenanceDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MaintenanceDocument
 */
class MaintenanceDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'equipment_unit_id' => $this->equipment_unit_id,
            'maintenance_record_id' => $this->maintenance_record_id,
            'document_type' => $this->document_type,
            'title' => $this->title,
            'original_name' => $this->original_name,
            'size_bytes' => $this->size_bytes,
            'valid_until' => $this->valid_until?->toIso8601String(),
            'is_expired' => $this->isExpired(),
            // Descarga autenticada: el archivo vive en disco privado.
            'file_url' => url("/api/maintenance-documents/{$this->id}/file"),
            'uploaded_by' => $this->uploaded_by,
            'uploader' => new UserResource($this->whenLoaded('uploader')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
