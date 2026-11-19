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
        ];
    }

    public function messages(): array
    {
        return [
            'hours.integer' => 'hours debe ser un número entero.',
            'hours.min' => 'hours debe ser al menos 1.',
            'hours.max' => 'hours no puede superar 168 (7 días).',
        ];
    }
}
