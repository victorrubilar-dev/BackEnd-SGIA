<?php

namespace App\Http\Requests\Export;

use App\Models\Location;

/** Filtros de `GET /api/locations/export` (los de `GET /api/locations` más la sala del plano). */
class ExportLocationsRequest extends ExportRequest
{
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            'tipo' => ['nullable', 'in:'.implode(',', Location::TIPOS)],
            'storeroom_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
        ];
    }
}
