<?php

namespace App\Http\Requests;

use App\Enums\SupplierArea;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendPurchaseRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', $this->route('purchaseRequest')) ?? false;
    }

    public function rules(): array
    {
        return [
            'areas' => ['required', 'array', 'min:1'],
            'areas.*' => ['required', 'string', 'distinct', Rule::enum(SupplierArea::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'areas.required' => 'Elige al menos un área de proveedores.',
            'areas.min' => 'Elige al menos un área de proveedores.',
            'areas.*.enum' => 'El área debe ser: '.implode(', ', SupplierArea::values()).'.',
            'areas.*.distinct' => 'Las áreas no pueden repetirse.',
        ];
    }
}
