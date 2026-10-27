<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCajonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) ($this->user()?->activo && $this->user()?->hasRole(['AD-01', 'DIR-01', 'PAN-01']));
    }

    public function rules(): array
    {
        return [
            'location_id' => ['sometimes', 'required_without:sala_id', 'integer', 'exists:salas,id'],
            'sala_id' => ['sometimes', 'required_without:location_id', 'integer', 'exists:salas,id'],
            'codigo' => ['sometimes', 'string', 'max:100', 'unique:estantes,codigo_barras'],
            'codigo_barras' => ['sometimes', 'string', 'max:100', 'unique:estantes,codigo_barras'],
            'nombre' => ['sometimes', 'string', 'max:100'],
            'descripcion' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'location_id.required_without' => 'La ubicación (sala/pañol) es obligatoria.',
            'sala_id.required_without' => 'La sala es obligatoria.',
            'location_id.exists' => 'La ubicación seleccionada no existe.',
            'sala_id.exists' => 'La sala seleccionada no existe.',
            'codigo.unique' => 'Ya existe un estante/cajón con este código.',
            'codigo_barras.unique' => 'Ya existe un estante/cajón con este código de barras.',
        ];
    }
}
