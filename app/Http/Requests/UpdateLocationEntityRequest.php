<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLocationEntityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) ($this->user()?->activo && $this->user()?->hasRole(['AD-01', 'DIR-01', 'PAN-01']));
    }

    public function rules(): array
    {
        return [
            'nombre' => ['sometimes', 'required', 'string', 'max:100'],
            'nombre_codigo' => ['nullable', 'string', 'max:100'],
            'panol_id' => ['nullable', 'integer', 'exists:panoles,id'],
            'edificio' => ['nullable', 'string', 'max:10'],
            'piso' => ['nullable', 'integer'],
            'tipo' => ['nullable', 'string'],
            'descripcion' => ['nullable', 'string', 'max:500'],
        ];
    }
}
