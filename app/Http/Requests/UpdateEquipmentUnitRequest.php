<?php

namespace App\Http\Requests;

use App\Models\EquipmentUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEquipmentUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('equipmentUnit')) ?? false;
    }

    public function rules(): array
    {
        $unit = $this->route('equipmentUnit');
        $id = $unit instanceof EquipmentUnit ? $unit->id : $unit;

        return [
            'code' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('equipment_units', 'code')->ignore($id)],
            'serial_number' => ['sometimes', 'nullable', 'string', 'max:100', Rule::unique('equipment_units', 'serial_number')->ignore($id)],
            'cajon_id' => ['sometimes', 'required', 'integer', 'exists:cajones,id'],
            'last_maintenance_at' => ['sometimes', 'nullable', 'date'],
            'next_maintenance_at' => ['sometimes', 'nullable', 'date'],
            'status' => ['prohibited'],
            'product_id' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'Ya existe una unidad con ese código.',
            'serial_number.unique' => 'Ya existe una unidad con ese número de serie.',
            'cajon_id.exists' => 'El cajón seleccionado no existe.',
            'status.prohibited' => 'El estado se cambia en PATCH /equipment-units/{id}/status.',
            'product_id.prohibited' => 'No se puede cambiar el producto de una unidad.',
        ];
    }
}
