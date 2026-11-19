<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOracleCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // El rol se controla con el middleware `role:DIR-01,PAN-01` de la ruta.
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            // Mismo formato que el maestro de Oracle: mayúsculas y espacios simples.
            $this->merge(['name' => mb_strtoupper(preg_replace('/\s+/u', ' ', trim($this->input('name'))) ?? '')]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('oracle_categories', 'name')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'La descripción de categoría es obligatoria.',
            'name.max' => 'La descripción de categoría no puede superar los 100 caracteres.',
            'name.unique' => 'Esa descripción de categoría ya existe.',
        ];
    }
}
