<?php

namespace App\Http\Requests;

use App\Models\Estante;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreCajonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()?->hasRole(['DIR-01', 'PAN-01']);
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
            'estante_id' => ['nullable', 'integer', 'exists:estantes,id'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            CajonCodeRules::validate($validator, (string) $this->input('codigo'), (int) $this->input('location_id'), true);

            if (! $validator->errors()->has('estante_id') && $this->filled('estante_id')) {
                $estanteLocation = Estante::whereKey($this->integer('estante_id'))->value('location_id');
                if ($estanteLocation !== null && (int) $estanteLocation !== $this->integer('location_id')) {
                    $validator->errors()->add('estante_id', 'El estante no pertenece a la sala del cajón.');
                }
            }
        }];
    }

    public function messages(): array
    {
        return [
            'location_id.required' => 'La ubicación (sala/pañol) es obligatoria.',
            'location_id.exists' => 'La ubicación seleccionada no existe.',
            'codigo.required' => 'El código del cajón es obligatorio.',
            'estante_id.exists' => 'El estante seleccionado no existe.',
        ];
    }
}
