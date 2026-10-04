<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;

class ScanInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('scanInvoice', Product::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'invoice_file' => [
                'required',
                'file',
                'mimes:pdf,png,jpg,jpeg,webp,json',
                'max:10240', // 10MB máx
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'invoice_file.required' => 'El archivo de la factura es obligatorio.',
            'invoice_file.file' => 'Debes enviar un archivo válido.',
            'invoice_file.mimes' => 'El formato del archivo debe ser PDF, imagen (PNG, JPG, WEBP) o JSON.',
            'invoice_file.max' => 'El archivo no puede exceder los 10MB.',
        ];
    }
}
