<?php

namespace App\Http\Requests;

use App\Models\Producto;
use Illuminate\Foundation\Http\FormRequest;

class UpdateLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $product = $this->route('product');

        return $this->user()?->can('update', $product) ?? false;
    }

    public function rules(): array
    {
        return [
            'cajon_id' => ['sometimes', 'nullable', 'integer', 'exists:estantes,id'],
            'estante_id' => ['sometimes', 'nullable', 'integer', 'exists:estantes,id'],
            'location_id' => ['sometimes', 'nullable', 'integer', 'exists:salas,id'],
            'sala_id' => ['sometimes', 'nullable', 'integer', 'exists:salas,id'],
            'sala' => ['nullable', 'string', 'max:50'],
            'cajon' => ['nullable', 'string', 'max:50'],
            'descripcion' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'cajon_id.exists' => 'El estante/cajón seleccionado no existe.',
            'estante_id.exists' => 'El estante seleccionado no existe.',
            'location_id.exists' => 'La sala/ubicación seleccionada no existe.',
            'sala_id.exists' => 'La sala seleccionada no existe.',
        ];
    }
}
