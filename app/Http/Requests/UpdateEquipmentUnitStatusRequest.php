<?php

namespace App\Http\Requests;

use App\Models\EquipmentUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEquipmentUnitStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('updateStatus', $this->route('equipmentUnit')) ?? false;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(EquipmentUnit::STATUSES)],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'El nuevo estado es obligatorio.',
            'status.in' => 'El estado debe ser disponible, prestado, en_reparacion o dado_de_baja.',
        ];
    }
}
