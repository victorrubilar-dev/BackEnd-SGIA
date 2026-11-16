<?php

namespace App\Http\Requests;

use App\Models\Cajon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateCajonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()?->hasRole(['AD-01', 'DIR-01', 'PAN-01']);
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
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            /** @var Cajon $cajon */
            $cajon = $this->route('cajon');

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
