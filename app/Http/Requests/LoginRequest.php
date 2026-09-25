<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', Rule::in(['web', 'mobile'])],
        ];
    }

    public function messages(): array
    {
        return [
            'device_name.in' => 'device_name debe ser web o mobile.',
        ];
    }
}