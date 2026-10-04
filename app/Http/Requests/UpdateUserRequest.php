<?php

namespace App\Http\Requests;

use App\Models\User;
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
        $userId = $targetUser instanceof User ? $targetUser->id : $targetUser;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'role' => ['sometimes', 'required', 'string', Rule::in(User::ROLES)],
            'area' => ['nullable', 'string', 'max:255'],
            'password' => [
                'sometimes',
                'nullable',
                'string',
                Password::min(8)->letters()->numbers(),
                'confirmed',
            ],
            'password_confirmation' => ['required_with:password', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre completo es requerido.',
            'email.required' => 'El correo electrónico es requerido.',
            'email.email' => 'El correo electrónico debe ser una dirección válida.',
            'email.unique' => 'Este correo electrónico ya está registrado por otro usuario.',
            'role.required' => 'El rol de usuario es requerido.',
            'role.in' => 'El rol seleccionado no es válido.',
            'password.confirmed' => 'La confirmación de la contraseña no coincide.',
            'password_confirmation.required_with' => 'Debes confirmar la contraseña al actualizarla.',
        ];
    }
}
