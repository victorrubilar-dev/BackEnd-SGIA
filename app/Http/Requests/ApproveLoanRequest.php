<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApproveLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('approve', $this->route('loan')) ?? false;
    }

    /**
     * La existencia, el producto, el estado y el pañol de cada unidad los valida LoanService.
     */
    public function rules(): array
    {
        return [
            'items' => ['sometimes', 'array'],
            'items.*.item_id' => ['required', 'integer'],
            'items.*.unit_ids' => ['required', 'array', 'min:1'],
            'items.*.unit_ids.*' => ['integer', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.*.item_id.required' => 'Cada ítem debe indicar item_id.',
            'items.*.unit_ids.required' => 'Indica las unidades que se entregan.',
            'items.*.unit_ids.*.distinct' => 'Las unidades no pueden repetirse.',
        ];
    }
}
