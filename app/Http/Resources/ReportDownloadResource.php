<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una descarga de reporte del usuario, leída de `audit_logs` (`report.export`), para «Mis descargas»
 * (docs/centro-reportes.md, decisión 13).
 *
 * @mixin AuditLog
 */
class ReportDownloadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $values = $this->new_values ?? [];

        return [
            'id' => $this->id,
            'report' => $values['report'] ?? null,
            'title' => $values['title'] ?? null,
            'filters' => (object) ($values['filters'] ?? []),
            'path' => $values['path'] ?? null,
            'query' => (object) ($values['query'] ?? []),
            'rows' => $values['rows'] ?? null,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
