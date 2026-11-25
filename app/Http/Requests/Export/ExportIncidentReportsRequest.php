<?php

namespace App\Http\Requests\Export;

use App\Models\IncidentReport;

/** Filtros de `GET /api/incident-reports/export` (los mismos de `GET /api/incident-reports`). */
class ExportIncidentReportsRequest extends ExportRequest
{
    protected ?string $model = IncidentReport::class;

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:'.implode(',', IncidentReport::STATUSES)],
            'severity' => ['nullable', 'in:'.implode(',', IncidentReport::SEVERITIES)],
            'request_type' => ['nullable', 'in:'.implode(',', IncidentReport::REQUEST_TYPES)],
            'product_id' => ['nullable', 'integer'],
            'equipment_unit_id' => ['nullable', 'integer'],
        ];
    }

    public function attributes(): array
    {
        return [...parent::attributes(), 'severity' => 'severidad', 'request_type' => 'tipo de solicitud'];
    }
}
