<?php

namespace App\Http\Requests;

use App\Models\UserChart;
use App\Services\MetricsService;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Forma de los campos; la combinación final (plantilla + campos enviados) la valida UserChartService.
 */
class StoreUserChartRequest extends FormRequest
{
    public function authorize(): bool|Response
    {
        return $this->user()?->can('create', UserChart::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'chart_template_id' => ['required', 'integer', 'exists:chart_templates,id'],
            'custom_title' => ['nullable', 'string', 'max:150'],
            'type' => ['nullable', 'string', Rule::in(MetricsService::CHART_TYPES)],
            'position' => ['nullable', 'integer', 'between:1,'.UserChart::MAX_POSITION],
            'entity' => ['nullable', 'string', Rule::in(MetricsService::entities())],
            'metric' => ['nullable', 'string', Rule::in(MetricsService::METRICS)],
            'group_by' => ['nullable', 'string', 'max:50'],
            'period' => ['nullable', 'string', Rule::in(MetricsService::TIME_RANGES)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'chart_template_id.required' => 'Elige una plantilla de gráfico.',
            'chart_template_id.exists' => 'La plantilla seleccionada no existe.',
            'custom_title.max' => 'El título no puede superar los 150 caracteres.',
            'type.in' => 'El tipo debe ser '.implode(', ', MetricsService::CHART_TYPES).'.',
            'position.between' => 'La posición debe estar entre 1 y '.UserChart::MAX_POSITION.'.',
            'entity.in' => 'La entidad debe ser '.implode(', ', MetricsService::entities()).'.',
            'metric.in' => 'La métrica debe ser '.implode(', ', MetricsService::METRICS).'.',
            'period.in' => 'El período debe ser '.implode(', ', MetricsService::TIME_RANGES).'.',
            'date_from.date' => 'La fecha inicial no es válida.',
            'date_to.date' => 'La fecha final no es válida.',
            'date_to.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
        ];
    }
}
