<?php

namespace App\Http\Requests;

use App\Models\Student;
use App\Rules\ChileanRut;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Student::class) ?? false;
    }

    /**
     * El RUT se guarda normalizado (`12345678-9`): así la unicidad no depende del formato.
     */
    protected function prepareForValidation(): void
    {
        $rut = $this->input('rut');

        if (is_string($rut) && ChileanRut::isValid($rut)) {
            $this->merge(['rut' => ChileanRut::normalize($rut)]);
        }
    }

    public function rules(): array
    {
        return [
            'rut' => ['required', 'string', new ChileanRut, Rule::unique('students', 'rut')->ignore($this->route('student'))],
            'full_name' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:150'],
            'career_id' => ['nullable', 'integer', 'exists:careers,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'rut.required' => 'El RUT del estudiante es obligatorio.',
            'rut.unique' => 'Ya existe un estudiante con ese RUT.',
            'full_name.max' => 'El nombre no puede superar los 150 caracteres.',
            'email.email' => 'El correo no es válido.',
            'career_id.exists' => 'La carrera seleccionada no existe.',
        ];
    }
}
