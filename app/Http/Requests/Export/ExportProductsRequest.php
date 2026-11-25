<?php

namespace App\Http\Requests\Export;

use App\Models\Product;

/** Filtros de `GET /api/products/export` (los mismos de `GET /api/products`). */
class ExportProductsRequest extends ExportRequest
{
    protected ?string $model = Product::class;

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', 'in:'.implode(',', Product::TYPES)],
            'area' => ['nullable', 'string', 'max:120'],
            'is_active' => ['nullable', 'boolean'],
            'critical_only' => ['nullable', 'boolean'],
            'sala' => ['nullable', 'string', 'max:120'],
            'cajon' => ['nullable', 'string', 'max:120'],
            'cajon_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'supplier_id' => ['nullable', 'integer'],
        ];
    }
}
