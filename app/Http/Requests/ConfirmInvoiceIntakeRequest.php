<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmInvoiceIntakeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('invoiceIntake')) ?? false;
    }

    public function rules(): array
    {
        return [
            'document_number' => ['required', 'string', 'max:100'],
            // Obligatorio si no hay orden de compra ni carrera detectada; si viene, reemplaza al del borrador.
            'annual_budget_id' => ['nullable', 'integer', 'exists:annual_budgets,id'],
            'purchase_id' => ['nullable', 'integer', 'exists:purchases,id'],
            // Atajo del flujo nuevo: liga la factura a la OC de la solicitud `SC-AAAA-NNNN`.
            'purchase_request_code' => ['nullable', 'string', 'max:20'],
            // Solo lo honra DIR-01; otro rol recibe 403 si hay exceso.
            'allow_over_budget' => ['sometimes', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'items.*.detected_name' => ['nullable', 'string', 'max:255'],
            'items.*.cajon_id' => ['nullable', 'integer', 'exists:cajones,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'document_number.required' => 'El número de factura es obligatorio.',
            'annual_budget_id.exists' => 'El presupuesto no existe.',
            'purchase_id.exists' => 'La orden de compra no existe.',
            'items.required' => 'Debes indicar al menos un ítem.',
            'items.min' => 'Debes indicar al menos un ítem.',
            'items.*.product_id.required' => 'Cada ítem debe indicar su producto.',
            'items.*.product_id.exists' => 'El producto del ítem no existe.',
            'items.*.quantity.required' => 'Cada ítem debe indicar su cantidad.',
            'items.*.quantity.min' => 'La cantidad de cada ítem debe ser al menos 1.',
            'items.*.unit_price.required' => 'Cada ítem debe indicar su precio unitario.',
            'items.*.unit_price.min' => 'El precio unitario no puede ser negativo.',
            'items.*.cajon_id.exists' => 'El cajón del ítem no existe.',
        ];
    }
}
