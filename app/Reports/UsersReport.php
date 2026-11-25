<?php

namespace App\Reports;

use App\Models\User;
use App\Support\XlsxWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Usuarios (`/usuarios`) con los filtros de `GET /api/users`. Tiene correos: la exportación queda en `audit_logs`.
 */
final class UsersReport implements Report
{
    /**
     * @param  Builder<User>  $query  Usuarios ya filtrados como en el listado.
     */
    public function __construct(private readonly Request $request, private readonly Builder $query) {}

    public function title(): string
    {
        return 'Usuarios';
    }

    public function filename(): string
    {
        return 'usuarios';
    }

    public function audited(): bool
    {
        return true;
    }

    public function filters(): array
    {
        $values = $this->request->query();
        if (array_key_exists('is_active', $values)) {
            $values['is_active'] = ReportFilters::yesNo($values['is_active']);
        }

        return ReportFilters::describe($values, [
            'search' => 'Búsqueda',
            'role' => 'Rol',
            'area' => 'Área',
            'career_id' => 'Carrera',
            'storeroom_id' => 'Pañol',
            'is_active' => 'Activo',
        ]);
    }

    public function sheets(): array
    {
        return [
            new ReportSheet(
                'Usuarios',
                ['Nombre', 'Correo', 'Rol', 'Área', 'Carreras', 'Pañol', 'Activo', 'Creado', 'Último acceso'],
                (clone $this->query)->with(['careers', 'storeroom'])->orderBy('name')->orderBy('id'),
                fn (User $u): array => [
                    $u->name,
                    $u->email,
                    $u->role,
                    $u->area,
                    $u->careers->pluck('name')->sort()->implode(', ') ?: null,
                    $u->storeroom?->name,
                    ReportValues::yesNo($u->is_active),
                    $u->created_at,
                    $u->last_login_at,
                ],
                [7 => XlsxWriter::DATE, 8 => XlsxWriter::DATETIME],
                [30, 32, 9, 26, 40, 22, 8, 12, 17],
            ),
        ];
    }
}
