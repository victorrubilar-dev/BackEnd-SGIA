<?php

namespace App\Http\Requests;

use App\Models\Proveedor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        $supplier = $this->route('supplier');

        return $this->user()?->can('update', $supplier) ?? false;
    }

    public function rules(): array
    {
        $supplier = $this->route('supplier');
        $supplierId = $supplier instanceof Proveedor ? $supplier->id : $supplier;

        return [
            'rut' => ['sometimes', 'string', 'max:20', Rule::unique('proveedores', 'rut')->ignore($supplierId)],
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'razon_social' => ['sometimes', 'required', 'string', 'max:150'],
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
            'rut.unique' => 'Ya existe un proveedor con este RUT.',
            'email.email' => 'El correo del proveedor debe ser una dirección válida.',
        ];
    }
}
