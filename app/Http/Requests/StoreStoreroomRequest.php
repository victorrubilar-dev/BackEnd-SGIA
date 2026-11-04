<?php

namespace App\Http\Requests;

use App\Models\Storeroom;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStoreroomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Storeroom::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9-]+$/', Rule::unique('storerooms', 'code')->ignore($this->route('storeroom'))],
            'general_location' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del pañol es obligatorio.',
            'code.required' => 'El código del pañol es obligatorio.',
            'code.regex' => 'El código solo admite letras, números y guiones (p. ej. PAN-TEMUCO).',
            'code.unique' => 'Ya existe un pañol con ese código.',
        ];
    }
}
