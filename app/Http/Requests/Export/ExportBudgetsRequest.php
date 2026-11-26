<?php

namespace App\Http\Requests\Export;

use App\Models\AnnualBudget;

/** Filtros de `GET /api/budgets/export` (los de `GET /api/budgets/summary`): sin `year`, todos los años; `from`/`to` acotan los movimientos. */
class ExportBudgetsRequest extends ExportRequest
{
    protected ?string $model = AnnualBudget::class;

    public function rules(): array
    {
        return [
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'career_id' => ['nullable', 'integer'],
            ...$this->dateRange(),
        ];
    }

    public function attributes(): array
    {
        return parent::attributes() + ['year' => 'año'];
    }
}
