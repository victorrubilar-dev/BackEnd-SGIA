<?php

namespace App\Http\Requests;

use App\Models\Purchase;
use Illuminate\Foundation\Http\FormRequest;

class ScanArrivalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $purchase = $this->route('purchase');

        return $this->user()?->can('scanArrival', $purchase) ?? false;
    }

    public function rules(): array
    {
        return [
            // Guía de despacho o factura de llegada (al menos uno de los tres campos)
            'document' => [
                'required_without_all:guide_number,invoice_number',
                'file',
                'mimes:pdf,png,jpg,jpeg,webp,json',
                'max:10240', // 10MB máx
            ],
            'guide_number' => ['nullable', 'string', 'max:100'],
            'invoice_number' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'document.required_without_all' => 'Debes adjuntar la guía de despacho / factura de llegada o indicar su número.',
            'document.file' => 'Debes enviar un archivo válido.',
            'document.mimes' => 'El formato del documento debe ser PDF, imagen (PNG, JPG, WEBP) o JSON.',
            'document.max' => 'El documento no puede exceder los 10MB.',
            'guide_number.string' => 'El número de guía debe ser texto.',
            'invoice_number.string' => 'El número de factura debe ser texto.',
        ];
    }
}
