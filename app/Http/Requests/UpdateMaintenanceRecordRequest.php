<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMaintenanceRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('maintenanceRecord')) ?? false;
    }

    public function rules(): array
    {
        return [
            'rev_a_approved' => ['sometimes', 'boolean'],
            'rev_b_approved' => ['sometimes', 'boolean'],
            // null = no aplica REV-C
            'rev_c_approved' => ['sometimes', 'nullable', 'boolean'],
            'pfg_approved' => ['sometimes', 'boolean'],
            'requires_additional_maintenance' => ['sometimes', 'boolean'],
            'applies_recf' => ['sometimes', 'boolean'],
            'repair_cost' => ['sometimes', 'numeric', 'min:0', 'max:9999999999999'],
            'spare_parts_used' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'technical_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            '*.boolean' => 'El campo debe ser verdadero o falso.',
            'repair_cost.numeric' => 'El costo de reparación debe ser un número.',
            'repair_cost.min' => 'El costo de reparación no puede ser negativo.',
        ];
    }
}
