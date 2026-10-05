<?php

namespace App\Http\Requests;

use App\Models\Purchase;
use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Purchase::class) ?? false;
    }

    public function rules(): array
    {
        return [
            // Se puede crear desde cero (proveedor + ítems) o partir de una
            // cotización aceptada (REQ-07 -> REQ-08) que aporte ambos datos.
            'quotation_id' => ['nullable', 'integer', 'exists:quotations,id'],
            'supplier_id' => ['required_without:quotation_id', 'integer', 'exists:suppliers,id'],
            'quotation_reference' => ['nullable', 'string', 'max:100'],
            'expected_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required_without:quotation_id', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'supplier_id.required_without' => 'El proveedor es obligatorio si no indicas una cotización aprobada.',
            'supplier_id.exists' => 'El proveedor seleccionado no existe en el sistema.',
            'quotation_id.exists' => 'La cotización seleccionada no existe en el sistema.',
            'items.required_without' => 'La orden de compra debe incluir al menos un producto si no indicas una cotización aprobada.',
            'items.array' => 'El campo items debe ser una lista.',
            'items.min' => 'La orden de compra debe incluir al menos un producto.',
            'items.*.name.required' => 'Cada producto de la orden debe tener nombre.',
            'items.*.quantity.required' => 'Cada producto de la orden debe tener cantidad.',
            'items.*.quantity.min' => 'La cantidad de cada producto debe ser positiva.',
            'items.*.unit_price.numeric' => 'El precio unitario debe ser numérico.',
            'items.*.product_id.exists' => 'El producto seleccionado no existe en el inventario.',
        ];
    }
}
