<?php

namespace App\Http\Requests;

use App\Models\Location;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()?->hasRole(['AD-01', 'DIR-01', 'PAN-01']);
    }

    public function rules(): array
    {
        return [
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
            'nombre.required' => 'El nombre de la ubicación es obligatorio.',
            'storeroom_id.exists' => 'El pañol seleccionado no existe.',
            'tipo.in' => 'El tipo de ubicación debe ser sala, panol, taller o bodega.',
        ];
    }
}
