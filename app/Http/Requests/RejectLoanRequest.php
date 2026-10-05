<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RejectLoanRequest extends FormRequest
{
    public function prepareForValidation(): void
    {
        // Acepta también el alias en español "motivo".
        if (! $this->has('rejection_reason') && $this->has('motivo')) {
            $this->merge(['rejection_reason' => $this->input('motivo')]);
        }
    }

    public function authorize(): bool
    {
        $loan = $this->route('loan');

        return $this->user()?->can('reject', $loan) ?? false;
    }

    public function rules(): array
    {
        return [
            'rejection_reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'rejection_reason.required' => 'El motivo del rechazo es obligatorio.',
            'rejection_reason.min' => 'El motivo del rechazo debe tener al menos 5 caracteres.',
            'rejection_reason.max' => 'El motivo del rechazo no puede superar los 1000 caracteres.',
        ];
    }
}
