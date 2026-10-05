<?php

namespace App\Http\Requests;

use App\Models\Product;
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
            'cajon_id' => ['sometimes', 'nullable', 'integer', 'exists:cajones,id'],
            'location_id' => ['sometimes', 'nullable', 'integer', 'exists:locations,id'],
            'sala' => ['required_without_all:cajon_id,location_id', 'nullable', 'string', 'max:50'],
            'cajon' => ['required_without_all:cajon_id,location_id', 'nullable', 'string', 'max:50'],
            'descripcion' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'sala.required_without_all' => 'Debes indicar la sala si no seleccionas un cajón o ubicación existente.',
            'cajon.required_without_all' => 'Debes indicar el cajón si no seleccionas un cajón o ubicación existente.',
            'cajon_id.exists' => 'El cajón seleccionado no existe.',
            'location_id.exists' => 'La ubicación seleccionada no existe.',
        ];
    }
}
