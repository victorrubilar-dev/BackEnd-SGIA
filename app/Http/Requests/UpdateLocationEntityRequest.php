<?php

namespace App\Http\Requests;

use App\Models\Location;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateLocationEntityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()?->hasRole(['AD-01', 'DIR-01', 'PAN-01']);
    }

    public function rules(): array
    {
        return [
            'nombre' => ['sometimes', 'required', 'string', 'max:100'],
            'tipo' => ['sometimes', 'string', Rule::in(Location::TIPOS)],
            'descripcion' => ['nullable', 'string', 'max:500'],
            'storeroom_id' => ['sometimes', 'integer', 'exists:storerooms,id'],
            'building' => ['nullable', 'string', 'max:10'],
            'floor' => ['nullable', 'integer'],
        ];
    }

    /**
     * `code` es inmutable: sin regla propia no llega a `validated()`; uno igual al actual se ignora.
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            /** @var Location $location */
            $location = $this->route('location');
            $code = $this->input('code');

            if (filled($code) && (! is_string($code) || Str::upper(trim($code)) !== $location->code)) {
                $validator->errors()->add('code', 'El código de la sala no se puede cambiar.');
            }
        }];
    }
}
