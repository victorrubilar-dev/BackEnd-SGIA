<?php

namespace App\Http\Requests;

use App\Models\EquipmentUnit;
use Illuminate\Foundation\Http\FormRequest;

class StoreEquipmentUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', EquipmentUnit::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'cajon_id' => ['required', 'integer', 'exists:cajones,id'],
            'count' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'code' => ['nullable', 'string', 'max:100', 'unique:equipment_units,code', 'prohibits:count'],
            'serial_number' => ['nullable', 'string', 'max:100', 'unique:equipment_units,serial_number', 'prohibits:count'],
            'serial_numbers' => ['sometimes', 'array'],
            'serial_numbers.*' => ['nullable', 'string', 'max:100', 'distinct', 'unique:equipment_units,serial_number'],
        ];
    }

    public function messages(): array
    {
        return [
            'cajon_id.required' => 'El cajón (estante) de la unidad es obligatorio.',
            'cajon_id.exists' => 'El cajón seleccionado no existe.',
            'count.min' => 'Debes crear al menos una unidad.',
            'count.max' => 'No puedes crear más de 200 unidades a la vez.',
            'code.unique' => 'Ya existe una unidad con ese código.',
            'code.prohibits' => 'El código manual solo se acepta al crear una unidad.',
            'serial_number.unique' => 'Ya existe una unidad con ese número de serie.',
            'serial_number.prohibits' => 'El número de serie individual solo se acepta al crear una unidad; usa serial_numbers.',
            'serial_numbers.*.unique' => 'Uno de los números de serie ya está registrado.',
            'serial_numbers.*.distinct' => 'Los números de serie no pueden repetirse.',
        ];
    }
}
