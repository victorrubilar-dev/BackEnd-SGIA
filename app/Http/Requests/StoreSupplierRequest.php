<?php

namespace App\Http\Requests;

use App\Models\Proveedor;
use Illuminate\Foundation\Http\FormRequest;

class StoreSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Proveedor::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required_without:razon_social', 'string', 'max:150'],
            'razon_social' => ['sometimes', 'required_without:name', 'string', 'max:150'],
            'rut' => ['nullable', 'string', 'max:20', 'unique:proveedores,rut'],
            'contact_name' => ['nullable', 'string', 'max:150'],
            'nombre_contacto' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:50'],
            'telefono' => ['nullable', 'string', 'max:50'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'activo' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required_without' => 'El nombre del proveedor es obligatorio.',
            'razon_social.required_without' => 'La razón social del proveedor es obligatoria.',
            'rut.unique' => 'Ya existe un proveedor con este RUT.',
            'email.email' => 'El correo del proveedor debe ser una dirección válida.',
        ];
    }
}
