<?php

namespace App\Http\Requests;

use App\Models\Usuario;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Usuario::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required_without:nombre', 'string', 'max:150'],
            'nombre' => ['required_without:name', 'string', 'max:150'],
            'email' => ['required', 'string', 'email', 'max:150', 'unique:usuarios,email'],
            'role' => ['nullable', 'string'],
            'rol_id' => ['nullable', 'integer', 'exists:roles,id'],
            'panol_id' => ['nullable', 'integer', 'exists:panoles,id'],
            'carreras' => ['nullable', 'array'],
            'carreras.*' => ['integer', 'exists:carreras,id'],
            'password' => [
                'required',
                'string',
                Password::min(8)->letters()->numbers(),
                'confirmed',
            ],
            'password_confirmation' => ['required', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'activo' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required_without' => 'El nombre completo es requerido.',
            'nombre.required_without' => 'El nombre completo es requerido.',
            'email.required' => 'El correo electrónico es requerido.',
            'email.email' => 'El correo electrónico debe ser una dirección válida.',
            'email.unique' => 'Este correo electrónico ya está registrado.',
            'password.required' => 'La contraseña es requerida.',
            'password.confirmed' => 'La confirmación de la contraseña no coincide.',
            'password_confirmation.required' => 'Debes confirmar la contraseña.',
        ];
    }
}
