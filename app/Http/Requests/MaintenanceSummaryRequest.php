<?php

namespace App\Http\Requests;

use App\Models\MaintenanceRecord;
use Illuminate\Foundation\Http\FormRequest;

class MaintenanceSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', MaintenanceRecord::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ];
    }

    public function messages(): array
    {
        return [
            'year.integer' => 'El año no es válido.',
            'year.between' => 'El año no es válido.',
            'from.date' => 'La fecha inicial no es válida.',
            'to.date' => 'La fecha final no es válida.',
            'to.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
        ];
    }
}
