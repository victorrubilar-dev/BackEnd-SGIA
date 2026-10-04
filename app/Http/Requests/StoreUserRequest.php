<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', 'string', Rule::in(User::ROLES)],
            'area' => ['nullable', 'string', 'max:255'],
            'password' => [
                'required',
                'string',
                Password::min(8)->letters()->numbers(),
                'confirmed',
            ],
            'password_confirmation' => ['required', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre completo es requerido.',
            'email.required' => 'El correo electrónico es requerido.',
            'email.email' => 'El correo electrónico debe ser una dirección válida.',
            'email.unique' => 'Este correo electrónico ya está registrado.',
            'role.required' => 'El rol de usuario es requerido.',
            'role.in' => 'El rol seleccionado no es válido.',
            'password.required' => 'La contraseña es requerida.',
            'password.confirmed' => 'La confirmación de la contraseña no coincide.',
            'password_confirmation.required' => 'Debes confirmar la contraseña.',
        ];
    }
}
