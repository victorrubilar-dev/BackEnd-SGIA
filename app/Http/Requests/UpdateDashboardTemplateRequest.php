<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateDashboardTemplateRequest extends FormRequest
{
    public function authorize(): bool|Response
    {
        return Gate::inspect('update', $this->route('dashboardTemplate'));
    }

    public function rules(): array
    {
        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:100',
                Rule::unique('dashboard_templates', 'name')
                    ->where('user_id', $this->user()?->id)
                    ->ignore($this->route('dashboardTemplate')),
            ],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return (new StoreDashboardTemplateRequest)->messages();
    }
}
