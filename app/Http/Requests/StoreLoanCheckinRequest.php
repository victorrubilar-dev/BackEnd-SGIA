<?php

namespace App\Http\Requests;

use App\Models\Loan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreLoanCheckinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('checkin', Loan::class);
    }

    /**
     * Condiciones aceptadas al devolver; `optimo` equivale a `bueno`.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'loan_id' => ['required', 'integer', 'exists:loans,id'],
            'general_notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required_without:items.*.product_id', 'nullable', 'integer'],
            'items.*.product_id' => ['required_without:items.*.item_id', 'nullable', 'integer'],
            'items.*.condition' => ['required', 'string', Rule::in(['bueno', 'optimo', 'regular', 'dañado', 'danado', 'extraviado'])],
            'items.*.damaged' => ['nullable', 'boolean'],
        ];
    }
}
