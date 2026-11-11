<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SelectPurchaseRequestQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', $this->route('purchaseRequest')) ?? false;
    }

    public function rules(): array
    {
        return [
            'quotation_id' => ['required', 'integer', Rule::exists('purchase_request_quotations', 'id')->where('purchase_request_id', $this->route('purchaseRequest')->id)],
        ];
    }

    public function messages(): array
    {
        return [
            'quotation_id.required' => 'Indica la cotización elegida.',
            'quotation_id.exists' => 'La cotización no pertenece a esta solicitud.',
        ];
    }
}
