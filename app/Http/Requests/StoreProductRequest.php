<?php

namespace App\Http\Requests;

use App\Models\Producto;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Producto::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required_without:nombre',
                'string',
                'max:255',
            ],
            'nombre' => [
                'required_without:name',
                'string',
                'max:255',
            ],
            'description' => ['nullable', 'string'],
            'descripcion' => ['nullable', 'string'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'sku' => ['nullable', 'string', 'max:50', 'unique:productos,sku'],
            'quantity' => ['required_without:stock_actual', 'integer', 'min:0'],
            'stock_actual' => ['required_without:quantity', 'integer', 'min:0'],
            'stock_minimo' => ['sometimes', 'integer', 'min:0'],
            'categoria_id' => ['nullable', 'integer', 'exists:categorias,id'],
            'carrera_id' => ['nullable', 'integer', 'exists:carreras,id'],
            'tipo' => ['nullable', 'string'],
            'precio_referencia' => ['nullable', 'numeric', 'min:0'],
            'foto_url' => ['nullable', 'string', 'max:500'],
            'photo_url' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required_without' => 'El nombre del producto es obligatorio.',
            'nombre.required_without' => 'El nombre del producto es obligatorio.',
            'quantity.min' => 'La cantidad de producto debe ser positiva o cero.',
            'sku.unique' => 'El SKU ya está registrado para otro producto.',
            'stock_minimo.min' => 'El stock mínimo no puede ser negativo.',
        ];
    }
}
