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
            // La compra parte siempre de una cotización aceptada (REQ-07 -> REQ-08);
            // proveedor e ítems son opcionales y, si faltan, se toman de la cotización.
            'annual_budget_id' => ['required', 'integer', 'exists:annual_budgets,id'],
            'quotation_id' => ['required_without:quotation_ids', 'nullable', 'integer', 'exists:quotations,id'],
            'quotation_ids' => ['nullable', 'array', 'min:3'],
            'quotation_ids.*' => ['integer', 'distinct', 'exists:quotations,id'],
            'quotation_justification' => ['nullable', 'string', 'max:1000'],
            'supplier_id' => ['required_with:quotation_ids', 'nullable', 'integer', 'exists:suppliers,id'],
            'quotation_reference' => ['nullable', 'string', 'max:100'],
            'expected_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['nullable', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required_with:items', 'numeric', 'min:0', 'max:9999999999'],
        ];
    }

    public function messages(): array
    {
        return [
            'annual_budget_id.required' => 'El presupuesto es obligatorio.',
            'annual_budget_id.exists' => 'El presupuesto no existe.',
            'items.*.unit_price.required_with' => 'Cada producto de la orden debe tener precio unitario.',
            'quotation_id.required' => 'Toda compra debe partir de una cotización aceptada.',
            'quotation_id.required_without' => 'Toda compra debe partir de una cotización aceptada (o de 3 o más con quotation_ids).',
            'quotation_justification.max' => 'El motivo no puede superar los 1000 caracteres.',
            'supplier_id.exists' => 'El proveedor seleccionado no existe en el sistema.',
            'quotation_id.exists' => 'La cotización seleccionada no existe en el sistema.',
            'quotation_ids.min' => 'Se requieren al menos 3 cotizaciones aceptadas para generar la orden de compra.',
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
