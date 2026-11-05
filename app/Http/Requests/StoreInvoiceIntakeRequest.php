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
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'El archivo de la factura es obligatorio.',
            'file.mimes' => 'El formato del archivo debe ser PDF, imagen (PNG, JPG, WEBP).',
            'file.max' => 'El archivo no puede exceder los 10MB.',
        ];
    }
}
