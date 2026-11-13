<?php

namespace App\Http\Requests;

use App\Models\DashboardTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDashboardTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', DashboardTemplate::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('dashboard_templates', 'name')->where('user_id', $this->user()?->id),
            ],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Ponle un nombre a la plantilla.',
            'name.max' => 'El nombre no puede superar los 100 caracteres.',
            'name.unique' => 'Ya tienes una plantilla con ese nombre.',
            'description.max' => 'La descripción no puede superar los 255 caracteres.',
        ];
    }
}
