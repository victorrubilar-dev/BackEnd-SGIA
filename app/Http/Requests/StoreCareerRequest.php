<?php

namespace App\Http\Requests;

use App\Models\Career;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCareerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole(User::ROLE_ADMIN) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('careers', 'name')],
            'level' => ['required', 'string', Rule::in(array_keys(Career::LEVELS))],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre de la carrera es obligatorio.',
            'name.unique' => 'Ya existe una carrera con ese nombre.',
            'level.required' => 'El nivel de la carrera es obligatorio.',
            'level.in' => 'El nivel debe ser tecnico o profesional.',
        ];
    }
}
