<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePurchaseRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', $this->route('purchaseRequest')) ?? false;
    }

    public function rules(): array
    {
        return [
            'annual_budget_id' => ['sometimes', 'integer', 'exists:annual_budgets,id'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            ...PurchaseRequestItemRules::rules(required: false),
        ];
    }

    public function messages(): array
    {
        return [
            'annual_budget_id.exists' => 'El presupuesto no existe.',
            ...PurchaseRequestItemRules::messages(),
        ];
    }
}
