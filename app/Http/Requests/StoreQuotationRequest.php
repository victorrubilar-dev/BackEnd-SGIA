<?php

namespace App\Http\Requests;

use App\Models\Quotation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Quotation::class) ?? false;
    }

    public function rules(): array
    {
        return [
            // Mínimo 1 producto con cantidad positiva
            'products' => ['required', 'array', 'min:1'],
            'products.*.id' => ['required', 'integer', 'exists:products,id'],
            'products.*.quantity' => ['required', 'integer', 'min:1'],
            'products.*.name' => ['sometimes', 'string', 'max:255'],

            // Mínimo 3 proveedores distintos
            'supplier_ids' => ['required', 'array', 'min:3', 'distinct'],
            'supplier_ids.*' => ['required', 'integer', Rule::exists('suppliers', 'id')],

            'notes' => ['nullable', 'string', 'max:1000'],
            'expires_at' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'products.required' => 'Debes indicar al menos un producto a cotizar.',
            'products.min' => 'Debes indicar al menos un producto a cotizar.',
            'products.array' => 'El campo products debe ser una lista.',
            'products.*.id.required' => 'Cada producto debe tener un identificador.',
            'products.*.id.exists' => 'El producto seleccionado no existe en el inventario.',
            'products.*.quantity.required' => 'Cada producto debe tener una cantidad.',
            'products.*.quantity.integer' => 'La cantidad debe ser un número entero.',
            'products.*.quantity.min' => 'La cantidad solicitada debe ser positiva.',
            'supplier_ids.required' => 'Debes seleccionar al menos 3 proveedores.',
            'supplier_ids.min' => 'Debes seleccionar al menos 3 proveedores.',
            'supplier_ids.distinct' => 'No puedes repetir el mismo proveedor.',
            'supplier_ids.*.exists' => 'Uno de los proveedores seleccionados no existe en el sistema.',
        ];
    }
}
