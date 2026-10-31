<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeliverLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('deliver', $this->route('loan')) ?? false;
    }

    public function rules(): array
    {
        return [
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer'],
            'items.*.unit_ids' => ['required', 'array', 'min:1'],
            'items.*.unit_ids.*' => ['integer', 'distinct', 'exists:equipment_units,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.*.item_id.required' => 'Cada ítem a reasignar debe indicar item_id.',
            'items.*.unit_ids.required' => 'Indica las unidades realmente entregadas.',
            'items.*.unit_ids.*.exists' => 'Una de las unidades indicadas no existe.',
            'items.*.unit_ids.*.distinct' => 'Las unidades no pueden repetirse.',
        ];
    }
}
