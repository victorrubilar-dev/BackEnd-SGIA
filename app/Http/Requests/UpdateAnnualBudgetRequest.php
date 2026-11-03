<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAnnualBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('annualBudget')) ?? false;
    }

    public function rules(): array
    {
        return [
            'initial_amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999'],
        ];
    }

    public function messages(): array
    {
        return [
            'initial_amount.required' => 'El monto inicial es obligatorio.',
            'initial_amount.gt' => 'El monto inicial debe ser mayor que cero.',
        ];
    }
}
