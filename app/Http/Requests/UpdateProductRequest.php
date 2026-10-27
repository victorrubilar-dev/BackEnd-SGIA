<?php

namespace App\Http\Requests;

use App\Models\Producto;
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
        $productId = $product instanceof Producto ? $product->id : $product;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'nombre' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'descripcion' => ['nullable', 'string'],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sku' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                Rule::unique('productos', 'sku')->ignore($productId),
            ],
            'quantity' => ['sometimes', 'integer', 'min:0'],
            'stock_actual' => ['sometimes', 'integer', 'min:0'],
            'stock_minimo' => ['sometimes', 'integer', 'min:0'],
            'categoria_id' => ['nullable', 'integer', 'exists:categorias,id'],
            'carrera_id' => ['nullable', 'integer', 'exists:carreras,id'],
            'tipo' => ['nullable', 'string'],
            'precio_referencia' => ['nullable', 'numeric', 'min:0'],
            'foto_url' => ['nullable', 'string', 'max:500'],
            'photo_url' => ['nullable', 'string', 'max:500'],
        ];
    }
}
