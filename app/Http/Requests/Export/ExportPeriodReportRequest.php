<?php

namespace App\Http\Requests\Export;

/** Filtros de `GET /api/reports/period`: el período es obligatorio (docs/centro-reportes.md, decisión 10). */
class ExportPeriodReportRequest extends ExportRequest
{
    public function rules(): array
    {
        return [
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'career_id' => ['nullable', 'integer'],
            'storeroom_id' => ['nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'from.required' => 'Indica la fecha de inicio del período.',
            'to.required' => 'Indica la fecha de término del período.',
        ];
    }
}
