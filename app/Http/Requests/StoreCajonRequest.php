<?php

namespace App\Http\Requests;

use App\Models\Cajon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCajonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && in_array($this->user()->role, ['AD-01', 'DIR-01', 'PAN-01'], true);
    }

    public function rules(): array
    {
        $locationId = $this->input('location_id');

        return [
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'codigo' => [
                'required',
                'string',
                'max:50',
                Rule::unique('cajones', 'codigo')->where(fn ($query) => $query->where('location_id', $locationId)),
            ],
            'descripcion' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'location_id.required' => 'La ubicación (sala/pañol) es obligatoria.',
            'location_id.exists' => 'La ubicación seleccionada no existe.',
            'codigo.required' => 'El código del cajón es obligatorio.',
            'codigo.unique' => 'Ya existe un cajón con este código en la ubicación seleccionada.',
        ];
    }
}
