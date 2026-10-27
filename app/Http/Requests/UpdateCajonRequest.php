<?php

namespace App\Http\Requests;

use App\Models\Estante;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCajonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) ($this->user()?->activo && $this->user()?->hasRole(['AD-01', 'DIR-01', 'PAN-01']));
    }

    public function rules(): array
    {
        $cajon = $this->route('cajon');
        $cajonId = $cajon instanceof Estante ? $cajon->id : $cajon;

        return [
            'location_id' => ['sometimes', 'integer', 'exists:salas,id'],
            'sala_id' => ['sometimes', 'integer', 'exists:salas,id'],
            'codigo' => [
                'sometimes',
                'string',
                'max:100',
                Rule::unique('estantes', 'codigo_barras')->ignore($cajonId),
            ],
            'codigo_barras' => [
                'sometimes',
                'string',
                'max:100',
                Rule::unique('estantes', 'codigo_barras')->ignore($cajonId),
            ],
            'nombre' => ['sometimes', 'string', 'max:100'],
            'descripcion' => ['nullable', 'string', 'max:500'],
        ];
    }
}
