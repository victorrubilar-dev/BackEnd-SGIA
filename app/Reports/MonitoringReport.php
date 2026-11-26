<?php

namespace App\Reports;

use App\Models\FailedLogin;
use App\Models\LoginRecord;
use App\Models\User;
use App\Support\XlsxWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Monitoreo (`/monitoreo`): inicios de sesión de las últimas `hours` horas (o del período `from`–`to`), intentos fallidos con los filtros de
 * `GET /api/monitoring/failed-logins` y sesiones activas. Tiene correos e IP: la exportación queda en `audit_logs`.
 */
final class MonitoringReport implements Report
{
    private const REASONS = [
        FailedLogin::REASON_INVALID_CREDENTIALS => 'Credenciales inválidas',
        FailedLogin::REASON_INACTIVE => 'Usuario desactivado',
        FailedLogin::REASON_THROTTLED => 'Bloqueado por intentos',
    ];

    /**
     * @param  Builder<LoginRecord>  $logins  Inicios de sesión de la ventana.
     * @param  Builder<FailedLogin>  $failed  Intentos fallidos ya filtrados.
     * @param  Builder<PersonalAccessToken>  $sessions  Tokens vigentes.
     */
    public function __construct(
        private readonly Request $request,
        private readonly int $hours,
        private readonly Builder $logins,
        private readonly Builder $failed,
        private readonly Builder $sessions,
    ) {}

    public function title(): string
    {
        return 'Monitoreo';
    }

    public function filename(): string
    {
        return 'monitoreo';
    }

    public function audited(): bool
    {
        return true;
    }

    public function filters(): array
    {
        $period = $this->request->filled('from') || $this->request->filled('to')
            ? []
            : ['Inicios de sesión' => 'últimas '.$this->hours.' horas'];

        return $period + ReportFilters::describe($this->request->query(), [
            'from' => 'Desde',
            'to' => 'Hasta',
            'email' => 'Correo (fallidos)',
            'ip_address' => 'IP (fallidos)',
            'reason' => ['Motivo (fallidos)', fn ($v) => is_string($v) ? self::REASONS[$v] ?? null : null],
        ]);
    }

    public function sheets(): array
    {
        return [
            (new ReportSheet(
                'Inicios de sesión',
                ['Fecha y hora', 'Usuario', 'Correo', 'Rol', 'Dispositivo', 'IP', 'Navegador'],
                (clone $this->logins)->with('user')->orderByDesc('logged_in_at')->orderByDesc('id'),
                fn (LoginRecord $r): array => [
                    $r->logged_in_at,
                    $r->user?->name,
                    $r->user?->email,
                    $r->user?->role,
                    $r->device_type,
                    $r->ip_address,
                    $r->user_agent,
                ],
                [0 => XlsxWriter::DATETIME],
                [17, 26, 30, 9, 14, 15, 50],
                yearOf: 0,
            ))->summarized(['Rol', 'Usuario']),
            new ReportSheet(
                'Fallidos',
                ['Fecha y hora', 'Correo', 'Usuario', 'Motivo', 'IP', 'Navegador'],
                (clone $this->failed)->with('user')->orderByDesc('attempted_at')->orderByDesc('id'),
                fn (FailedLogin $f): array => [
                    $f->attempted_at,
                    $f->email,
                    $f->user?->name,
                    self::REASONS[$f->reason] ?? $f->reason,
                    $f->ip_address,
                    $f->user_agent,
                ],
                [0 => XlsxWriter::DATETIME],
                [17, 30, 26, 22, 15, 50],
                yearOf: 0,
            ),
            new ReportSheet(
                'Sesiones activas',
                ['Usuario', 'Correo', 'Rol', 'Dispositivo', 'Último uso', 'Creada', 'Expira'],
                (clone $this->sessions)->with('tokenable')->orderByRaw('last_used_at desc nulls last')->orderByDesc('id'),
                function (PersonalAccessToken $t): array {
                    $user = $t->tokenable instanceof User ? $t->tokenable : null;

                    return [
                        $user?->name,
                        $user?->email,
                        $user?->role,
                        $t->name,
                        $t->last_used_at,
                        $t->created_at,
                        $t->expires_at,
                    ];
                },
                [4 => XlsxWriter::DATETIME, 5 => XlsxWriter::DATETIME, 6 => XlsxWriter::DATETIME],
                [26, 30, 9, 24, 17, 17, 17],
            ),
        ];
    }
}
