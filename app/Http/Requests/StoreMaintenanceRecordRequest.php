<?php

namespace App\Http\Requests;

use App\Models\MaintenanceRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMaintenanceRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', MaintenanceRecord::class) ?? false;
    }

    public function rules(): array
    {
        $unit = $this->route('equipmentUnit');

        return [
            'maintenance_type' => ['required', 'string', Rule::in(MaintenanceRecord::TYPES)],
            'execution_mode' => ['sometimes', 'string', Rule::in(MaintenanceRecord::MODES)],
            'external_supplier_id' => ['nullable', 'required_if:execution_mode,'.MaintenanceRecord::MODE_EXTERNAL, 'integer', 'exists:suppliers,id'],
            'incident_report_id' => ['nullable', 'integer', Rule::exists('incident_reports', 'id')->where('equipment_unit_id', $unit?->id)],
            'responsible_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
            'technical_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'maintenance_type.required' => 'El tipo de mantención es obligatorio.',
            'maintenance_type.in' => 'El tipo de mantención debe ser preventiva, correctiva o calibracion.',
            'execution_mode.in' => 'La modalidad debe ser medios_propios o externa.',
            'external_supplier_id.required_if' => 'La mantención externa exige el proveedor que la realiza.',
            'external_supplier_id.exists' => 'El proveedor seleccionado no existe.',
            'incident_report_id.exists' => 'El informe de novedades no existe o no es de esta unidad.',
            'responsible_id.exists' => 'El responsable no existe o está desactivado.',
        ];
    }
}
