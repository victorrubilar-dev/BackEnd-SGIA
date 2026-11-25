<?php

namespace App\Http\Requests\Export;

use App\Models\InvoiceIntake;
use Illuminate\Validation\Rule;

/** Filtros de `GET /api/invoice-intakes/export`: los de `GET /api/invoice-intakes` más la OC y el código de solicitud. */
class ExportInvoiceIntakesRequest extends ExportRequest
{
    protected ?string $model = InvoiceIntake::class;

    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', Rule::in(InvoiceIntake::STATUSES)],
            'annual_budget_id' => ['nullable', 'integer'],
            'purchase_id' => ['nullable', 'integer'],
            'purchase_request_code' => ['nullable', 'string', 'max:20'],
            ...$this->dateRange(),
        ];
    }

    public function attributes(): array
    {
        return parent::attributes() + ['annual_budget_id' => 'presupuesto', 'purchase_id' => 'orden de compra', 'purchase_request_code' => 'código de solicitud'];
    }
}
