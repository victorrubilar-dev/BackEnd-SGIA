<?php

namespace App\Http\Requests;

use App\Models\AnnualBudget;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAnnualBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        // DIR-01 solo crea para sus carreras; un career_id inexistente lo reporta la validación.
        return $user !== null
            && $user->can('create', AnnualBudget::class)
            && ($user->isAdmin() || $user->can('createFor', [AnnualBudget::class, (int) $this->input('career_id')]));
    }

    public function rules(): array
    {
        return [
            'career_id' => ['required', 'integer', 'exists:careers,id'],
            'year' => [
                'required', 'integer', 'between:2000,2100',
                Rule::unique('annual_budgets', 'year')->where('career_id', $this->input('career_id')),
            ],
            'initial_amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999'],
        ];
    }

    public function messages(): array
    {
        return [
            'career_id.required' => 'La carrera es obligatoria.',
            'career_id.exists' => 'La carrera no existe.',
            'year.required' => 'El año es obligatorio.',
            'year.unique' => 'Ya existe un presupuesto de esa carrera para ese año.',
            'initial_amount.required' => 'El monto inicial es obligatorio.',
            'initial_amount.gt' => 'El monto inicial debe ser mayor que cero.',
        ];
    }
}
