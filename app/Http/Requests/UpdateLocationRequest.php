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
            'location_id' => ['sometimes', 'nullable', 'integer', 'exists:locations,id'],
            'sala' => ['required_without:location_id', 'nullable', 'string', 'max:50'],
            'cajon' => ['required_without:location_id', 'nullable', 'string', 'max:50'],
            'descripcion' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'sala.required_without' => 'Debes indicar la sala si no seleccionas una ubicación existente.',
            'cajon.required_without' => 'Debes indicar el cajón si no seleccionas una ubicación existente.',
            'location_id.exists' => 'La ubicación seleccionada no existe.',
        ];
    }
}
