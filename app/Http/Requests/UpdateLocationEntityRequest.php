<?php

namespace App\Http\Requests;

use App\Models\Location;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLocationEntityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && in_array($this->user()->role, ['AD-01', 'DIR-01', 'PAN-01'], true);
    }

    public function rules(): array
    {
        return [
            'nombre' => ['sometimes', 'required', 'string', 'max:100'],
            'tipo' => ['sometimes', 'string', Rule::in(Location::TIPOS)],
            'descripcion' => ['nullable', 'string', 'max:500'],
        ];
    }
}
