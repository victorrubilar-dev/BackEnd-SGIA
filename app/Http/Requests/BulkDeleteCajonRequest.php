<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkDeleteCajonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasRole(['AD-01', 'DIR-01']);
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'exists:cajones,id'],
            'partial' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required' => 'Debes indicar al menos un cajón para eliminar.',
            'ids.array' => 'El campo ids debe ser una lista de identificadores.',
            'ids.min' => 'Debes seleccionar al menos un cajón para eliminar.',
            'ids.*.integer' => 'Los identificadores deben ser numéricos.',
            'ids.*.exists' => 'Uno o más cajones seleccionados no existen.',
        ];
    }
}
