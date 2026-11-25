<?php

namespace App\Http\Requests\Export;

use App\Models\FailedLogin;
use Illuminate\Validation\Rule;

/**
 * Filtros de `GET /api/monitoring/export`: `hours` de `GET /api/monitoring/logins` y `email`, `ip_address`,
 * `reason` de `GET /api/monitoring/failed-logins`. Solo AD-01 (middleware de la ruta).
 */
class ExportMonitoringRequest extends ExportRequest
{
    public const REASONS = [FailedLogin::REASON_INVALID_CREDENTIALS, FailedLogin::REASON_INACTIVE, FailedLogin::REASON_THROTTLED];

    public function rules(): array
    {
        return [
            'hours' => ['nullable', 'integer', 'min:1', 'max:168'],
            'email' => ['nullable', 'string', 'max:250'],
            'ip_address' => ['nullable', 'string', 'max:45'],
            'reason' => ['nullable', 'string', Rule::in(self::REASONS)],
        ];
    }

    public function messages(): array
    {
        return parent::messages() + [
            'hours.min' => 'hours debe ser al menos 1.',
            'hours.max' => 'hours no puede superar 168 (7 días).',
        ];
    }

    public function attributes(): array
    {
        return parent::attributes() + ['hours' => 'horas', 'email' => 'correo', 'ip_address' => 'IP', 'reason' => 'motivo'];
    }
}
