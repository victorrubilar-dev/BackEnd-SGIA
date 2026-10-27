<?php

namespace App\Http\Requests;

use App\Models\Usuario;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $targetUser = $this->route('user');

        return $this->user()?->can('update', $targetUser) ?? false;
    }

    public function rules(): array
    {
        $targetUser = $this->route('user');
        $userId = $targetUser instanceof Usuario ? $targetUser->id : $targetUser;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'nombre' => ['sometimes', 'required', 'string', 'max:150'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:150',
                Rule::unique('usuarios', 'email')->ignore($userId),
            ],
            'role' => ['sometimes', 'nullable', 'string'],
            'rol_id' => ['sometimes', 'nullable', 'integer', 'exists:roles,id'],
            'panol_id' => ['sometimes', 'nullable', 'integer', 'exists:panoles,id'],
            'carreras' => ['sometimes', 'nullable', 'array'],
            'carreras.*' => ['integer', 'exists:carreras,id'],
            'password' => [
                'sometimes',
                'nullable',
                'string',
                Password::min(8)->letters()->numbers(),
                'confirmed',
            ],
            'password_confirmation' => ['required_with:password', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'activo' => ['sometimes', 'boolean'],
        ];
    }
}
