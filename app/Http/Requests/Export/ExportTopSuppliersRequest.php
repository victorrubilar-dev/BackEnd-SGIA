<?php

namespace App\Http\Requests\Export;

/** Filtros de `GET /api/reports/top-suppliers` (docs/reportes-rankings.md): período obligatorio, carrera y `top`. */
class ExportTopSuppliersRequest extends ExportPeriodReportRequest
{
    public function rules(): array
    {
        return [
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'career_id' => ['nullable', 'integer'],
            'top' => ['nullable', 'integer', 'between:1,100'],
        ];
    }

    public function attributes(): array
    {
        return [...parent::attributes(), 'top' => 'mostrar'];
    }
}
