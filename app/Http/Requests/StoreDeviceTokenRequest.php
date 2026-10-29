<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDeviceTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:255', 'regex:/^Expo(nent)?PushToken\[.+\]$/'],
            'platform' => ['nullable', Rule::in(['ios', 'android'])],
        ];
    }

    public function messages(): array
    {
        return ['token.regex' => 'El token debe ser un ExpoPushToken válido.'];
    }
}
