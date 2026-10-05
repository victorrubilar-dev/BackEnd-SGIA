<?php

namespace App\Http\Requests;

use App\Models\Quotation;
use App\Models\QuotationSupplier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQuotationResponseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $quotation = $this->route('quotation');

        return $this->user()?->can('recordResponse', $quotation) ?? false;
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'status' => [
                'required',
                'string',
                Rule::in([QuotationSupplier::STATUS_ACCEPTED, QuotationSupplier::STATUS_REJECTED]),
            ],
            'offer_total' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'supplier_id.required' => 'El proveedor que responde es obligatorio.',
            'supplier_id.exists' => 'El proveedor seleccionado no existe en el sistema.',
            'status.required' => 'Debes indicar la respuesta del proveedor.',
            'status.in' => 'La respuesta del proveedor debe ser "aceptada" o "rechazada".',
            'offer_total.numeric' => 'La oferta debe ser un valor numérico.',
            'offer_total.min' => 'La oferta no puede ser negativa.',
        ];
    }
}
