<?php

namespace App\Http\Requests;

use App\Models\Estante;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateEstanteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()?->hasRole(['DIR-01', 'PAN-01']);
    }

    public function rules(): array
    {
        $size = ['sometimes', 'required', 'integer', 'between:'.Estante::MIN_SIZE.','.Estante::MAX_SIZE];

        return [
            'codigo' => ['sometimes', 'required', 'string', 'max:50'],
            'nombre' => ['nullable', 'string', 'max:80'],
            'descripcion' => ['nullable', 'string', 'max:500'],
            'zona_id' => ['nullable', 'integer'],
            'width' => $size,
            'depth' => $size,
            'rotation' => ['sometimes', 'required', 'integer', Rule::in(Estante::ROTATIONS)],
            'x' => ['sometimes', 'required', 'integer', 'min:0'],
            'y' => ['sometimes', 'required', 'integer', 'min:0'],
        ];
    }

    /**
     * Valida con los valores resultantes (lo enviado sobre lo guardado). `location_id` es inmutable.
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            /** @var Estante $estante */
            $estante = $this->route('estante');

            if ($this->has('location_id') && (int) $this->input('location_id') !== $estante->location_id) {
                $validator->errors()->add('location_id', 'Un estante no se puede mover a otra sala.');

                return;
            }

            EstanteRules::validate($validator, $estante->location, [
                'codigo' => (string) $this->input('codigo', $estante->codigo),
                'zona_id' => $this->has('zona_id') ? ($this->filled('zona_id') ? $this->integer('zona_id') : null) : $estante->zona_id,
                'width' => $this->integer('width', $estante->width),
                'depth' => $this->integer('depth', $estante->depth),
                'rotation' => $this->integer('rotation', $estante->rotation),
                'x' => $this->integer('x', $estante->x),
                'y' => $this->integer('y', $estante->y),
            ], $this->has('codigo'), $estante->id);
        }];
    }

    public function messages(): array
    {
        return ['rotation.in' => 'La orientación debe ser 0 o 90 grados.'];
    }
}
