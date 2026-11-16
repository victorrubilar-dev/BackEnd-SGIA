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
            // Equipos y maquetas: unidades escaneadas y su cajón; insumos: `cajon_id` (ver LoanService::checkin).
            'items.*.units' => ['sometimes', 'array', 'min:1'],
            'items.*.units.*' => ['array'],
            'items.*.units.*.id' => ['required', 'integer', 'distinct', 'exists:equipment_units,id'],
            'items.*.units.*.cajon_id' => ['nullable', 'integer', 'exists:cajones,id'],
            'items.*.cajon_id' => ['nullable', 'integer', 'exists:cajones,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.*.units.array' => 'Las unidades devueltas deben enviarse como lista.',
            'items.*.units.min' => 'Escanea las unidades que se devuelven.',
            'items.*.units.*.array' => 'Cada unidad devuelta debe indicar su id y su cajón.',
            'items.*.units.*.id.required' => 'Falta el id de una de las unidades devueltas.',
            'items.*.units.*.id.integer' => 'El id de la unidad debe ser un número.',
            'items.*.units.*.id.distinct' => 'Una unidad no puede devolverse dos veces en la misma operación.',
            'items.*.units.*.id.exists' => 'Una de las unidades indicadas no existe.',
            'items.*.units.*.cajon_id.integer' => 'El cajón de la unidad debe ser un número.',
            'items.*.units.*.cajon_id.exists' => 'El cajón escaneado no existe.',
            'items.*.cajon_id.integer' => 'El cajón debe ser un número.',
            'items.*.cajon_id.exists' => 'El cajón escaneado no existe.',
        ];
    }
}
