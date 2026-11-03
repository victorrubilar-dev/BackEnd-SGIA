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
