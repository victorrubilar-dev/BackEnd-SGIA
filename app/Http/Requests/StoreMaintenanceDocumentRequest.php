<?php

namespace App\Http\Requests;

use App\Models\MaintenanceDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMaintenanceDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', MaintenanceDocument::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'document_type' => ['required', 'string', Rule::in(MaintenanceDocument::TYPES)],
            'title' => ['required', 'string', 'max:150'],
            'file' => ['required', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:10240'],
            'valid_until' => ['nullable', 'date'],
            'maintenance_record_id' => [
                'nullable', 'integer',
                Rule::exists('maintenance_records', 'id')->where('equipment_unit_id', $this->route('equipmentUnit')?->id),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'document_type.required' => 'El tipo de documento es obligatorio.',
            'document_type.in' => 'El tipo debe ser certificado_calibracion, informe_tecnico, manual, garantia u otro.',
            'title.required' => 'El título del documento es obligatorio.',
            'title.max' => 'El título no puede superar los 150 caracteres.',
            'file.required' => 'Adjunta el documento en PDF.',
            'file.mimes' => 'El documento debe ser un PDF.',
            'file.mimetypes' => 'El documento debe ser un PDF.',
            'file.max' => 'El documento no puede superar los 10 MB.',
            'valid_until.date' => 'La fecha de vigencia no es válida.',
            'maintenance_record_id.exists' => 'La mantención no existe o no es de esta unidad.',
        ];
    }
}
