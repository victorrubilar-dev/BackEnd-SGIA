<?php

namespace App\Http\Requests\Export;

use App\Models\Loan;
use Illuminate\Validation\Rule;

/** Filtros de `GET /api/reports/top-teachers` (docs/reportes-rankings.md): período obligatorio, carrera, pañol, tipo y `top`. */
class ExportTopTeachersRequest extends ExportPeriodReportRequest
{
    public function rules(): array
    {
        return [
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'career_id' => ['nullable', 'integer'],
            'storeroom_id' => ['nullable', 'integer'],
            'tipo' => ['nullable', Rule::in([Loan::TYPE_DIRECT, Loan::TYPE_REMOTE])],
            'top' => ['nullable', 'integer', 'between:1,100'],
        ];
    }

    public function attributes(): array
    {
        return [...parent::attributes(), 'top' => 'mostrar'];
    }
}
