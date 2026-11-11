<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseRequestOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', $this->route('purchaseRequest')) ?? false;
    }

    public function rules(): array
    {
        return [
            'order_number' => ['required', 'string', 'max:100'],
            'file' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg,webp', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'order_number.required' => 'Indica el número de la orden de compra.',
            'file.required' => 'El archivo de la orden de compra es obligatorio.',
            'file.mimes' => 'El archivo debe ser PDF o imagen (PNG, JPG, WEBP).',
            'file.max' => 'El archivo no puede exceder los 10MB.',
        ];
    }
}
