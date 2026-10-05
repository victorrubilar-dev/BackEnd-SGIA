<?php

namespace App\Http\Requests;

use App\Models\Purchase;
use App\Services\PurchaseStateMachine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePurchaseStatusRequest extends FormRequest
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
        $purchase = $this->route('purchase');

        return $this->user()?->can('updateStatus', $purchase) ?? false;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(PurchaseStateMachine::STATUSES)],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'El estado de la orden es obligatorio.',
            'status.in' => 'El estado debe ser: pendiente, en_camino o completa.',
        ];
    }
}
