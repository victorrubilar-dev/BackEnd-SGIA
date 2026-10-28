<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkDeleteCajonRequest extends FormRequest
{
    /**
     * Normaliza los alias aceptados por el frontend:
     * estante_ids / cajon_ids -> ids
     */
    public function prepareForValidation(): void
    {
        $ids = $this->input('ids') ?? $this->input('estante_ids') ?? $this->input('cajon_ids');

        if ($ids !== null) {
            $this->merge(['ids' => $ids]);
        }
    }

    public function authorize(): bool
    {
        return (bool) ($this->user()?->activo && $this->user()?->hasRole(['AD-01', 'DIR-01']));
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'exists:estantes,id'],
            'partial' => ['sometimes', 'boolean'],
            'ignore_conflicts' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required' => 'Debes indicar al menos un estante o cajón para eliminar.',
            'ids.array' => 'El campo ids debe ser una lista de identificadores.',
            'ids.min' => 'Debes seleccionar al menos un estante o cajón para eliminar.',
            'ids.*.required' => 'Cada identificador de estante es obligatorio.',
            'ids.*.integer' => 'Los identificadores deben ser numéricos.',
            'ids.*.exists' => 'Uno o más estantes seleccionados no existen en el sistema.',
        ];
    }
}
