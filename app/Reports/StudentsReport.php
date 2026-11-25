<?php

namespace App\Reports;

use App\Models\Student;
use App\Support\XlsxWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Estudiantes (`/estudiantes`) con los filtros del listado. Tiene RUT y correo: la exportación se audita.
 */
final class StudentsReport implements Report
{
    /** @param  Builder<Student>  $query  Consulta ya filtrada, con `loans_count` y `active_loans_count`. */
    public function __construct(private readonly Request $request, private readonly Builder $query) {}

    public function title(): string
    {
        return 'Estudiantes';
    }

    public function filename(): string
    {
        return 'estudiantes';
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
            'career_id' => 'Carrera',
            'is_active' => 'Activo',
        ]);
    }

    public function sheets(): array
    {
        return [
            new ReportSheet(
                'Estudiantes',
                ['RUT', 'Nombre', 'Carrera', 'Correo', 'Activo', 'Préstamos', 'Préstamos en curso', 'Registrado'],
                (clone $this->query)->orderBy('full_name')->orderBy('id'),
                fn (Student $s): array => [
                    $s->rut,
                    $s->full_name,
                    $s->career?->name,
                    $s->email,
                    ReportValues::yesNo($s->is_active),
                    $s->loans_count,
                    $s->getAttribute('active_loans_count'),
                    $s->registered_at ?? $s->created_at,
                ],
                [5 => XlsxWriter::INTEGER, 6 => XlsxWriter::INTEGER, 7 => XlsxWriter::DATE],
                [14, 34, 30, 32, 8, 11, 18, 12],
            ),
        ];
    }
}
