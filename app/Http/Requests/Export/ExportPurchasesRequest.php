<?php

namespace App\Http\Requests\Export;

use App\Models\Purchase;
use Illuminate\Validation\Rule;

/** Filtros de `GET /api/purchases/export` (los mismos de `GET /api/purchases`; `estado` es alias de `status`). */
class ExportPurchasesRequest extends ExportRequest
{
    protected ?string $model = Purchase::class;

    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', Rule::in(Purchase::STATUSES)],
            'estado' => ['nullable', 'string', Rule::in(Purchase::STATUSES)],
            'supplier_id' => ['nullable', 'integer'],
            'quotation_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:120'],
            'linkable' => ['nullable', 'boolean'],
            ...$this->dateRange(),
        ];
    }

    public function attributes(): array
    {
        return parent::attributes() + ['estado' => 'estado', 'supplier_id' => 'proveedor', 'quotation_id' => 'cotización', 'linkable' => 'esperando factura'];
    }
}
