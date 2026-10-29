<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductConditionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('product')) ?? false;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['send_to_repair', 'repaired', 'write_off'])],
            'quantity' => ['required', 'integer', 'min:1'],
            // Desde dónde se da de baja: del stock disponible o de lo que está en taller.
            'source' => ['required_if:action,write_off', 'nullable', Rule::in(['stock', 'repair'])],
        ];
    }
}
