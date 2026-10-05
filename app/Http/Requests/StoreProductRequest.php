<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Product::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-zA-Z0-9\sáéíóúÁÉÍÓÚñÑüÜ\-\.\,\/]+$/u',
            ],
            'description' => ['nullable', 'string'],
            'barcode' => ['nullable', 'string', 'max:50', 'unique:products,barcode'],
            'quantity' => ['required', 'integer', 'min:0'],
            'stock_minimo' => ['sometimes', 'integer', 'min:0'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'cajon_id' => ['nullable', 'integer', 'exists:cajones,id'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'sala' => ['sometimes', 'nullable', 'string', 'max:50'],
            'cajon' => ['sometimes', 'nullable', 'string', 'max:50'],
            'descripcion' => ['sometimes', 'nullable', 'string', 'max:255'],
            'area' => ['nullable', 'string', 'max:255'],
            'photo_url' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del producto es obligatorio.',
            'name.regex' => 'El nombre del producto no debe contener caracteres especiales.',
            'quantity.required' => 'La cantidad de stock es obligatoria.',
            'quantity.integer' => 'La cantidad debe ser un número entero.',
            'quantity.min' => 'La cantidad de producto debe ser positiva o cero.',
            'supplier_id.exists' => 'El proveedor seleccionado no existe en el sistema.',
            'cajon_id.exists' => 'El cajón seleccionado no existe.',
            'location_id.exists' => 'La ubicación seleccionada no es válida.',
            'barcode.unique' => 'El código de barras ya está registrado para otro producto.',
            'stock_minimo.min' => 'El stock mínimo no puede ser negativo.',
        ];
    }
}
