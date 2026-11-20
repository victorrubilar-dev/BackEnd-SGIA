<?php

namespace App\Http\Requests;

use App\Models\Estante;
use App\Models\Location;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreEstanteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()?->hasRole(['DIR-01', 'PAN-01']);
    }

    public function rules(): array
    {
        $size = ['required', 'integer', 'between:'.Estante::MIN_SIZE.','.Estante::MAX_SIZE];

        return [
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'codigo' => ['required', 'string', 'max:50'],
            'nombre' => ['nullable', 'string', 'max:80'],
            'descripcion' => ['nullable', 'string', 'max:500'],
            'zona_id' => ['nullable', 'integer'],
            'width' => $size,
            'depth' => $size,
            'rotation' => ['sometimes', 'integer', Rule::in(Estante::ROTATIONS)],
            'x' => ['nullable', 'required_with:y', 'integer', 'min:0'],
            'y' => ['nullable', 'required_with:x', 'integer', 'min:0'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            EstanteRules::validate($validator, Location::findOrFail($this->integer('location_id')), [
                'codigo' => (string) $this->input('codigo'),
                'zona_id' => $this->filled('zona_id') ? $this->integer('zona_id') : null,
                'width' => $this->integer('width'),
                'depth' => $this->integer('depth'),
                'rotation' => $this->integer('rotation', 0),
                'x' => $this->filled('x') ? $this->integer('x') : null,
                'y' => $this->filled('y') ? $this->integer('y') : null,
            ], true);
        }];
    }

    public function messages(): array
    {
        return [
            'location_id.required' => 'La sala es obligatoria.',
            'location_id.exists' => 'La sala seleccionada no existe.',
            'codigo.required' => 'El código del estante es obligatorio.',
            'rotation.in' => 'La orientación debe ser 0 o 90 grados.',
        ];
    }
}
