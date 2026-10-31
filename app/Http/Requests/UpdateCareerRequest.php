<?php

namespace App\Http\Requests;

use App\Models\Career;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCareerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole(User::ROLE_ADMIN) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('careers', 'name')->ignore($this->route('career'))],
            'code' => ['sometimes', 'nullable', 'string', Rule::in(Career::CODES), Rule::unique('careers', 'code')->ignore($this->route('career'))],
            'level' => ['sometimes', 'required', 'string', Rule::in(array_keys(Career::LEVELS))],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre de la carrera es obligatorio.',
            'name.unique' => 'Ya existe una carrera con ese nombre.',
            'code.in' => 'El código debe ser ISD, II, IC o AP.',
            'code.unique' => 'Ya existe una carrera con ese código.',
            'level.required' => 'El nivel de la carrera es obligatorio.',
            'level.in' => 'El nivel debe ser tecnico o profesional.',
        ];
    }
}
