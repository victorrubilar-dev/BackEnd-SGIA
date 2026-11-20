<?php

namespace App\Http\Requests;

use App\Models\Zona;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateZonaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->is_active && $this->user()->hasRole(['DIR-01', 'PAN-01']);
    }

    public function rules(): array
    {
        $size = ['sometimes', 'required', 'integer', 'between:'.Zona::MIN_SIZE.','.Zona::MAX_SIZE];

        return [
            'nombre' => ['sometimes', 'required', 'string', 'max:'.Zona::NOMBRE_MAX_LENGTH],
            'x' => ['sometimes', 'required', 'integer', 'min:0'],
            'y' => ['sometimes', 'required', 'integer', 'min:0'],
            'width' => $size,
            'depth' => $size,
        ];
    }

    /**
     * Valida con los valores resultantes. `location_id` es inmutable.
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            /** @var Zona $zona */
            $zona = $this->route('zona');

            if ($this->has('location_id') && (int) $this->input('location_id') !== $zona->location_id) {
                $validator->errors()->add('location_id', 'Una zona no se puede mover a otra sala.');

                return;
            }

            ZonaRules::validate($validator, $zona->location, [
                'nombre' => (string) $this->input('nombre', $zona->nombre),
                'x' => $this->integer('x', $zona->x),
                'y' => $this->integer('y', $zona->y),
                'width' => $this->integer('width', $zona->width),
                'depth' => $this->integer('depth', $zona->depth),
            ], $zona->id);
        }];
    }
}
