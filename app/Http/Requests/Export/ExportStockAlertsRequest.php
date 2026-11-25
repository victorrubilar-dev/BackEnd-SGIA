<?php

namespace App\Http\Requests\Export;

use App\Models\StockAlert;

/** Filtros de `GET /api/alerts/export` (los mismos de `GET /api/alerts/critical-stock`). */
class ExportStockAlertsRequest extends ExportRequest
{
    public function rules(): array
    {
        return [
            'alert_type' => ['nullable', 'in:'.StockAlert::TYPE_WARNING.','.StockAlert::TYPE_CRITICAL],
            'is_resolved' => ['nullable', 'boolean'],
            'exclude_dismissed' => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [...parent::attributes(), 'alert_type' => 'tipo de alerta', 'is_resolved' => 'resuelta'];
    }
}
