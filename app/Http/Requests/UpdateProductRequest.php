<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        $product = $this->route('product');

        return $this->user()?->can('update', $product) ?? false;
    }

    public function rules(): array
    {
        $product = $this->route('product');
        $productId = $product instanceof Product ? $product->id : $product;

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                'regex:/^[a-zA-Z0-9\sáéíóúÁÉÍÓÚñÑüÜ\-\.\,\/]+$/u',
            ],
            'description' => ['nullable', 'string'],
            'barcode' => [
                'sometimes',
                'string',
                'max:50',
                Rule::unique('products', 'barcode')->ignore($productId),
            ],
            'quantity' => ['sometimes', 'required', 'integer', 'min:0'],
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
            'name.regex' => 'El nombre del producto no debe contener caracteres especiales.',
            'quantity.min' => 'La cantidad de producto debe ser positiva o cero.',
            'supplier_id.exists' => 'El proveedor seleccionado no existe en el sistema.',
            'cajon_id.exists' => 'El cajón seleccionado no existe.',
            'barcode.unique' => 'El código de barras ya está registrado para otro producto.',
            'stock_minimo.min' => 'El stock mínimo no puede ser negativo.',
        ];
    }
}
