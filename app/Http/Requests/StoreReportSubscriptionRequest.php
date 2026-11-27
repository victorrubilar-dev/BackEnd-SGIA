<?php

namespace App\Http\Requests;

use App\Models\ReportSubscription;
use App\Reports\ReportRoutes;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** `POST /api/me/report-subscriptions` (docs/suscripcion-reportes.md): solo rutas de la lista cerrada. */
class StoreReportSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'report_id' => ['required', 'string', Rule::in(array_keys(ReportRoutes::ALLOWED))],
            'title' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F]/'],
            'path' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) {
                $reportId = $this->input('report_id');
                if (! is_string($reportId) || $value !== ReportRoutes::path($reportId)) {
                    $fail('No se puede suscribir a esa ruta.');
                }
            }],
            'params' => ['nullable', 'array', 'max:15', function (string $attribute, mixed $value, Closure $fail) {
                foreach ((array) $value as $key => $param) {
                    $scalar = $param === null || is_scalar($param);
                    if (! preg_match('/^[a-z_]{1,40}$/', (string) $key) || ! $scalar || mb_strlen((string) $param) > 200) {
                        $fail('Los filtros del reporte no son válidos.');

                        return;
                    }
                }
            }],
            'frequency' => ['required', Rule::in(ReportSubscription::FREQUENCIES)],
        ];
    }

    public function messages(): array
    {
        return [
            'report_id.required' => 'Indica el reporte.',
            'report_id.in' => 'No se puede suscribir a ese reporte.',
            'title.required' => 'Indica el título del reporte.',
            'title.max' => 'El título es demasiado largo.',
            'title.not_regex' => 'El título no es válido.',
            'path.required' => 'Indica la ruta del reporte.',
            'params.array' => 'Los filtros del reporte no son válidos.',
            'params.max' => 'Demasiados filtros.',
            'frequency.required' => 'Elige la frecuencia.',
            'frequency.in' => 'La frecuencia no es válida.',
        ];
    }
}
