<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        // El rol lo controla el middleware `role:AD-01` de la ruta.
        return true;
    }

    public function rules(): array
    {
        return [
            'hours' => ['sometimes', 'integer', 'min:1', 'max:168'],
            // Con `from` / `to` se usa ese período en vez de `hours` (docs/centro-reportes.md, decisión 6).
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ];
    }

    public function messages(): array
    {
        return [
            'hours.integer' => 'hours debe ser un número entero.',
            'hours.min' => 'hours debe ser al menos 1.',
            'hours.max' => 'hours no puede superar 168 (7 días).',
            'from.date' => 'La fecha inicial no es válida.',
            'to.date' => 'La fecha final no es válida.',
            'to.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
        ];
    }
}
