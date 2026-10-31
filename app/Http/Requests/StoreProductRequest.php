<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'type' => ['sometimes', 'string', Rule::in(Product::TYPES)],
            'quantity' => ['required', 'integer', 'min:0'],
            'stock_minimo' => ['sometimes', 'integer', 'min:0'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'cajon_id' => ['nullable', 'integer', 'exists:cajones,id'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'sala' => ['sometimes', 'nullable', 'string', 'max:50'],
            'cajon' => ['sometimes', 'nullable', 'string', 'max:50'],
            'descripcion' => ['sometimes', 'nullable', 'string', 'max:255'],
            'area' => ['nullable', 'string', 'max:255'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'career_id' => [Rule::requiredIf(fn () => $this->input('type') === Product::TYPE_SUPPLY && empty($this->input('barcode'))), 'nullable', 'integer', Rule::exists('careers', 'id')->whereNotNull('code')],
            'maintenance_priority' => ['sometimes', 'string', Rule::in(Product::MAINTENANCE_PRIORITIES)],
            'requires_calibration' => ['sometimes', 'boolean'],
            'maintenance_frequency_months' => ['nullable', 'integer', 'min:1', 'max:240'],
            'reference_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'photo_url' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del producto es obligatorio.',
            'name.regex' => 'El nombre del producto no debe contener caracteres especiales.',
            'type.in' => 'El tipo de producto debe ser equipo, insumo o maqueta.',
            'category_id.required' => 'La categoría del producto es obligatoria.',
            'category_id.exists' => 'La categoría seleccionada no existe.',
            'career_id.required' => 'La carrera es obligatoria para generar el código de barras del insumo.',
            'career_id.exists' => 'La carrera seleccionada no existe o no tiene código.',
            'maintenance_priority.in' => 'La prioridad de mantención debe ser A, B o C.',
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
