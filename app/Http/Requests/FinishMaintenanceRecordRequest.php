<?php

namespace App\Http\Requests;

use App\Models\MaintenanceRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FinishMaintenanceRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('maintenanceRecord')) ?? false;
    }

    public function rules(): array
    {
        return [
            'result' => ['required', 'string', Rule::in(MaintenanceRecord::RESULTS)],
            'technical_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'result.required' => 'Indica el resultado de la mantención (operativo o baja).',
            'result.in' => 'El resultado debe ser operativo o baja.',
        ];
    }
}
