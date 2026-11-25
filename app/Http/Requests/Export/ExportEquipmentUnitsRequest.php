<?php

namespace App\Http\Requests\Export;

use App\Models\EquipmentUnit;

/** Filtros de `GET /api/equipment-units/export` (los mismos de `GET /api/equipment-units`). */
class ExportEquipmentUnitsRequest extends ExportRequest
{
    protected ?string $model = EquipmentUnit::class;

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            'product_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:'.implode(',', EquipmentUnit::STATUSES)],
            'cajon_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'storeroom_id' => ['nullable', 'integer'],
            'maintenance_due' => ['nullable', 'boolean'],
        ];
    }
}
