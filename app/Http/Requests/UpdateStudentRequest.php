<?php

namespace App\Http\Requests;

class UpdateStudentRequest extends StoreStudentRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('student')) ?? false;
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $rules['rut'][0] = 'sometimes';

        return $rules;
    }
}
