<?php

namespace App\Http\Requests;

use App\Models\Location;
use App\Models\Zona;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreZonaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->is_active && $this->user()->hasRole(['DIR-01', 'PAN-01']);
    }

    public function rules(): array
    {
        $size = ['required', 'integer', 'between:'.Zona::MIN_SIZE.','.Zona::MAX_SIZE];

        return [
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'nombre' => ['required', 'string', 'max:'.Zona::NOMBRE_MAX_LENGTH],
            'x' => ['required', 'integer', 'min:0'],
            'y' => ['required', 'integer', 'min:0'],
            'width' => $size,
            'depth' => $size,
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            ZonaRules::validate($validator, Location::findOrFail($this->integer('location_id')), [
                'nombre' => (string) $this->input('nombre'),
                'x' => $this->integer('x'),
                'y' => $this->integer('y'),
                'width' => $this->integer('width'),
                'depth' => $this->integer('depth'),
            ]);
        }];
    }

    public function messages(): array
    {
        return [
            'location_id.required' => 'La sala es obligatoria.',
            'location_id.exists' => 'La sala seleccionada no existe.',
            'nombre.required' => 'El nombre de la zona es obligatorio.',
        ];
    }
}
