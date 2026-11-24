<?php

namespace App\Http\Requests;

use App\Models\Cajon;
use App\Models\Estante;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateCajonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()?->hasRole(['DIR-01', 'PAN-01']);
    }

    public function rules(): array
    {
        return [
            'location_id' => ['sometimes', 'required', 'integer', 'exists:locations,id'],
            'codigo' => [
                'sometimes',
                'required',
                'string',
                'max:50',
            ],
            'descripcion' => ['nullable', 'string', 'max:500'],
            'estante_id' => ['nullable', 'integer', 'exists:estantes,id'],
            'lleno' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            /** @var Cajon $cajon */
            $cajon = $this->route('cajon');

            if (! $validator->errors()->has('estante_id') && $this->filled('estante_id')) {
                $estanteLocation = Estante::whereKey($this->integer('estante_id'))->value('location_id');
                if ($estanteLocation !== null && (int) $estanteLocation !== $this->integer('location_id', $cajon->location_id)) {
                    $validator->errors()->add('estante_id', 'El estante no pertenece a la sala del cajón.');
                }
            }

            if (! $this->has('codigo') && ! $this->has('location_id')) {
                return;
            }

            CajonCodeRules::validate(
                $validator,
                (string) $this->input('codigo', $cajon->codigo),
                (int) $this->input('location_id', $cajon->location_id),
                $this->has('codigo'),
                $cajon->id,
            );
        }];
    }
}
