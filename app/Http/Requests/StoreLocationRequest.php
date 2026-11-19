<?php

namespace App\Http\Requests;

use App\Models\Location;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()?->hasRole(['DIR-01', 'PAN-01']);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => Str::upper(trim($this->input('code')))]);
        }
    }

    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'regex:'.Location::CODE_PATTERN, 'unique:locations,code'],
            'nombre' => ['required', 'string', 'max:100'],
            'tipo' => ['sometimes', 'string', Rule::in(Location::TIPOS)],
            'descripcion' => ['nullable', 'string', 'max:500'],
            'storeroom_id' => ['sometimes', 'integer', 'exists:storerooms,id'],
            'building' => ['nullable', 'string', 'max:10'],
            'floor' => ['nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.string' => 'El código de la sala debe tener solo letras y números, de 2 a 10 caracteres.',
            'code.regex' => 'El código de la sala debe tener solo letras y números, de 2 a 10 caracteres.',
            'code.unique' => 'Ya existe una sala con el código :input.',
            'nombre.required' => 'El nombre de la ubicación es obligatorio.',
            'storeroom_id.exists' => 'El pañol seleccionado no existe.',
            'tipo.in' => 'El tipo de ubicación debe ser sala, panol, taller o bodega.',
        ];
    }
}
