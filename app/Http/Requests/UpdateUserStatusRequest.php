<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class UpdateUserStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $targetUser = $this->route('user');

        return $this->user()?->can('updateStatus', $targetUser) ?? false;
    }

    public function rules(): array
    {
        return [
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'is_active.required' => 'El estado is_active es requerido.',
            'is_active.boolean' => 'El estado is_active debe ser verdadero o falso.',
        ];
    }
}
