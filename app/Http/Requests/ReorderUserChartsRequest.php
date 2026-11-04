<?php

namespace App\Http\Requests;

use App\Models\UserChart;
use Illuminate\Foundation\Http\FormRequest;

class ReorderUserChartsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', UserChart::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'positions' => ['required', 'array', 'min:1', 'max:'.UserChart::MAX_POSITION],
            'positions.*.id' => ['required', 'integer', 'distinct'],
            'positions.*.position' => ['required', 'integer', 'between:1,'.UserChart::MAX_POSITION, 'distinct'],
        ];
    }

    public function messages(): array
    {
        return [
            'positions.required' => 'Envía la lista de posiciones ([{id, position}]).',
            'positions.max' => 'No puedes tener más de '.UserChart::MAX_POSITION.' gráficos.',
            'positions.*.id.distinct' => 'Un gráfico aparece dos veces.',
            'positions.*.position.between' => 'La posición debe estar entre 1 y '.UserChart::MAX_POSITION.'.',
            'positions.*.position.distinct' => 'Dos gráficos no pueden ocupar la misma posición.',
        ];
    }
}
