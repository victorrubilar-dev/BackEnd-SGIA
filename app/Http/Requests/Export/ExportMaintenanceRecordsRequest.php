<?php

namespace App\Http\Requests\Export;

use App\Models\MaintenanceRecord;

/** Filtros de `GET /api/maintenance-records/export` (los mismos de `GET /api/maintenance-records`). */
class ExportMaintenanceRecordsRequest extends ExportRequest
{
    protected ?string $model = MaintenanceRecord::class;

    public function rules(): array
    {
        return [
            ...$this->dateRange(),
            'career_id' => ['nullable', 'integer'],
            'sin_carrera' => ['nullable', 'boolean'],
            'status' => ['nullable', 'in:'.implode(',', MaintenanceRecord::STATUSES)],
            'maintenance_type' => ['nullable', 'in:'.implode(',', MaintenanceRecord::TYPES)],
            'execution_mode' => ['nullable', 'in:'.implode(',', MaintenanceRecord::MODES)],
            'product_id' => ['nullable', 'integer'],
            'equipment_unit_id' => ['nullable', 'integer'],
        ];
    }

    public function attributes(): array
    {
        return [...parent::attributes(), 'sin_carrera' => 'sin carrera', 'maintenance_type' => 'tipo', 'execution_mode' => 'ejecución'];
    }
}
