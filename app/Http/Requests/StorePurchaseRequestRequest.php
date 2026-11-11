<?php

namespace App\Http\Requests;

use App\Models\PurchaseRequest;
use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', PurchaseRequest::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'annual_budget_id' => ['required', 'integer', 'exists:annual_budgets,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
            ...PurchaseRequestItemRules::rules(required: true),
        ];
    }

    public function messages(): array
    {
        return [
            'annual_budget_id.required' => 'Selecciona el presupuesto (carrera y año) de la solicitud.',
            'annual_budget_id.exists' => 'El presupuesto no existe.',
            ...PurchaseRequestItemRules::messages(),
        ];
    }
}
