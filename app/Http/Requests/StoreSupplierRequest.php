<?php

namespace App\Http\Requests;

use App\Enums\SupplierArea;
use App\Models\Supplier;
use App\Rules\ChileanRut;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Supplier::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'rut' => ['nullable', 'string', 'max:20', new ChileanRut, Rule::unique('suppliers', 'rut')],
            'address' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'areas' => ['required', 'array', 'min:1'],
            'areas.*' => ['required', 'string', 'distinct', Rule::enum(SupplierArea::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del proveedor es obligatorio.',
            'rut.unique' => 'Ya existe un proveedor con ese RUT.',
            'email.email' => 'El correo del proveedor debe ser una dirección válida.',
            'areas.required' => 'Indica al menos un área del proveedor.',
            'areas.min' => 'Indica al menos un área del proveedor.',
            'areas.*.enum' => 'El área debe ser: '.implode(', ', SupplierArea::values()).'.',
            'areas.*.distinct' => 'Las áreas no pueden repetirse.',
        ];
    }
}
