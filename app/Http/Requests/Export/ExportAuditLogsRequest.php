<?php

namespace App\Http\Requests\Export;

use App\Models\AuditLog;
use Illuminate\Validation\Rule;

/**
 * Filtros de `GET /api/audit-logs/export` (los mismos de `GET /api/audit-logs`; `action` terminada en `.`
 * filtra por prefijo). Solo AD-01 (middleware de la ruta).
 */
class ExportAuditLogsRequest extends ExportRequest
{
    public function rules(): array
    {
        return [
            'action' => ['nullable', 'string', 'max:100'],
            'auditable_type' => ['nullable', 'string', Rule::in([...array_keys(AuditLog::TYPES), AuditLog::REPORT_TYPE])],
            'auditable_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            ...$this->dateRange(),
        ];
    }

    public function attributes(): array
    {
        return parent::attributes() + ['action' => 'acción', 'auditable_type' => 'tipo de entidad', 'auditable_id' => 'ID de entidad', 'user_id' => 'usuario'];
    }
}
