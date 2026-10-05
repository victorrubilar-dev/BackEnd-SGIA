<?php

namespace App\Http\Requests;

use App\Models\Quotation;
use App\Services\QuotationStateMachine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateQuotationStatusRequest extends FormRequest
{
    public function prepareForValidation(): void
    {
        // Acepta también el alias en español "estado".
        if (! $this->has('status') && $this->has('estado')) {
            $this->merge(['status' => $this->input('estado')]);
        }
    }

    public function authorize(): bool
    {
        $quotation = $this->route('quotation');

        return $this->user()?->can('updateStatus', $quotation) ?? false;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(QuotationStateMachine::STATUSES)],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'El estado de la cotización es obligatorio.',
            'status.in' => 'El estado debe ser: pendiente, aceptada, rechazada o convertida.',
        ];
    }
}
