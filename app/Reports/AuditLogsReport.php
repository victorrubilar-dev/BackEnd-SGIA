<?php

namespace App\Reports;

use App\Models\AuditLog;
use App\Support\XlsxWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Auditoría (`/auditoria`): eventos con los filtros de `GET /api/audit-logs` y los cambios en texto
 * («campo: antes → después»). Tiene correos e IP: la exportación queda en `audit_logs`.
 */
final class AuditLogsReport implements Report
{
    private const TYPES = [
        'user' => 'Usuario',
        'equipment_unit' => 'Unidad',
        'product' => 'Producto',
        'purchase' => 'Orden de compra',
        'invoice_intake' => 'Factura',
        'purchase_request' => 'Solicitud de compra',
        AuditLog::REPORT_TYPE => 'Reporte',
    ];

    /**
     * @param  Builder<AuditLog>  $query  Eventos ya filtrados como en el listado.
     */
    public function __construct(private readonly Request $request, private readonly Builder $query) {}

    public function title(): string
    {
        return 'Auditoría';
    }

    public function filename(): string
    {
        return 'auditoria';
    }

    public function audited(): bool
    {
        return true;
    }

    public function filters(): array
    {
        return ReportFilters::describe($this->request->query(), [
            'action' => ['Acción', fn ($v) => is_string($v) && str_ends_with($v, '.') ? $v.'*' : null],
            'auditable_type' => ['Entidad', fn ($v) => is_string($v) ? self::TYPES[$v] ?? null : null],
            'auditable_id' => 'ID de entidad',
            'user_id' => 'Usuario',
            'from' => 'Desde',
            'to' => 'Hasta',
        ]);
    }

    public function sheets(): array
    {
        return [
            (new ReportSheet(
                'Eventos',
                ['Fecha y hora', 'Usuario', 'Correo', 'Rol', 'Acción', 'Entidad', 'ID', 'Cambios', 'IP', 'Navegador'],
                (clone $this->query)->with('user')->orderByDesc('created_at')->orderByDesc('id'),
                fn (AuditLog $log): array => [
                    $log->created_at,
                    $log->user?->name,
                    $log->user?->email,
                    $log->user?->role,
                    $log->action,
                    self::TYPES[$log->auditable_type] ?? $log->auditable_type,
                    $log->auditable_id ?: null,
                    self::changes($log->old_values, $log->new_values),
                    $log->ip_address,
                    $log->user_agent,
                ],
                [0 => XlsxWriter::DATETIME, 6 => XlsxWriter::INTEGER],
                [17, 24, 28, 9, 26, 18, 8, 70, 15, 40],
                yearOf: 0,
            ))->summarized(['Acción', 'Usuario']),
        ];
    }

    /**
     * «campo: antes → después» por cada campo; sin valores previos (creación), solo «campo: valor».
     *
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public static function changes(?array $old, ?array $new): ?string
    {
        $old ??= [];
        $new ??= [];
        $parts = [];
        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $field) {
            $parts[] = $old === []
                ? $field.': '.self::value($new[$field] ?? null)
                : $field.': '.self::value($old[$field] ?? null).' → '.self::value($new[$field] ?? null);
        }

        return $parts === [] ? null : implode('; ', $parts);
    }

    private static function value(mixed $value): string
    {
        return match (true) {
            $value === null || $value === '' => '—',
            is_bool($value) => $value ? 'sí' : 'no',
            is_array($value) => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => (string) $value,
        };
    }
}
