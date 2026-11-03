<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInvoiceIntakeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null; // el rol y la carrera se validan con la policy en el controlador
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg,webp', 'max:10240'],
            'annual_budget_id' => ['required', 'integer', 'exists:annual_budgets,id'],
            'purchase_id' => ['nullable', 'integer', 'exists:purchases,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'El archivo de la factura es obligatorio.',
            'file.mimes' => 'El formato del archivo debe ser PDF, imagen (PNG, JPG, WEBP).',
            'file.max' => 'El archivo no puede exceder los 10MB.',
            'annual_budget_id.required' => 'El presupuesto es obligatorio.',
            'annual_budget_id.exists' => 'El presupuesto no existe.',
            'purchase_id.exists' => 'La compra no existe.',
        ];
    }
}
