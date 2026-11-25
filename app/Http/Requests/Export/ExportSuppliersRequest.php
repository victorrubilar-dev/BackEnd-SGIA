<?php

namespace App\Http\Requests\Export;

use App\Enums\SupplierArea;
use App\Models\Supplier;
use Illuminate\Validation\Rule;

/** Filtros de `GET /api/suppliers/export` (los mismos de `GET /api/suppliers`). */
class ExportSuppliersRequest extends ExportRequest
{
    protected ?string $model = Supplier::class;

    public function rules(): array
    {
        return [
            'area' => ['nullable', 'string', Rule::enum(SupplierArea::class)],
            'category' => ['nullable', 'string', 'max:120'],
            'is_active' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function messages(): array
    {
        return parent::messages() + ['area.enum' => 'El área debe ser: '.implode(', ', SupplierArea::values()).'.'];
    }

    public function attributes(): array
    {
        return parent::attributes() + ['area' => 'área', 'category' => 'categoría'];
    }
}
