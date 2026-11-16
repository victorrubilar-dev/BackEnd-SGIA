<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreCajonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()?->hasRole(['AD-01', 'DIR-01', 'PAN-01']);
    }

    public function rules(): array
    {
        return [
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'codigo' => [
                'required',
                'string',
                'max:50',
            ],
            'descripcion' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            CajonCodeRules::validate($validator, (string) $this->input('codigo'), (int) $this->input('location_id'), true);
        }];
    }

    public function messages(): array
    {
        return [
            'location_id.required' => 'La ubicación (sala/pañol) es obligatoria.',
            'location_id.exists' => 'La ubicación seleccionada no existe.',
            'codigo.required' => 'El código del cajón es obligatorio.',
        ];
    }
}
