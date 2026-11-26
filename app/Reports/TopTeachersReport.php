<?php

namespace App\Reports;

use App\Models\Career;
use App\Models\Loan;
use App\Models\User;
use App\Services\LoanService;
use App\Support\XlsxWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * «Profesores con más préstamos» (`GET /api/reports/top-teachers`, docs/reportes-rankings.md): ranking de los
 * profesores (`PRO-01`) por préstamos con `loan_date` entre `from` y `to`, sin contar los rechazados, y la hoja
 * «Préstamos» con los préstamos de los profesores del ranking. PAN-01 ve solo los de su pañol (`Loan::visibleTo`).
 */
final class TopTeachersReport implements ScopedReport
{
    /** @var Builder<Loan> */
    private Builder $loans;

    /** @var list<array{user_id: int, name: string, loans: int, direct: int, remote: int, rejected: int, units: int, overdue: int, subjects: ?string, careers: ?string, last_date: ?Carbon}>|null */
    private ?array $ranking = null;

    public function __construct(private readonly Request $request, LoanService $loanService)
    {
        /** @var User $user */
        $user = $request->user();

        $this->loans = $loanService->applyHistoryFilters(Loan::visibleTo($user), $request)
            ->whereBetween('loan_date', [$request->date('from')->toDateString(), $request->date('to')->toDateString()])
            ->whereIn('requested_by', User::query()->withRole(User::ROLE_TEACHER)->select('id'));
    }

    public function title(): string
    {
        return 'Profesores con más préstamos';
    }

    public function filename(): string
    {
        return 'profesores-mas-prestamos';
    }

    public function audited(): bool
    {
        return false;
    }

    public function scope(?User $user): string
    {
        return (new LoansReport($this->request, $this->loans))->scope($user);
    }

    public function filters(): array
    {
        $values = $this->request->query();
        $values['tipo'] ??= $values['type'] ?? null;
        if (! empty($values['tipo'])) {
            $values['tipo'] = ReportValues::label((string) $values['tipo']);
        }

        return ReportFilters::describe($values, [
            'from' => 'Desde',
            'to' => 'Hasta',
            'tipo' => 'Tipo',
            'career_id' => 'Carrera',
            'storeroom_id' => 'Pañol',
            'top' => ['Mostrar', fn ($v) => is_numeric($v) ? 'los primeros '.(int) $v : null],
        ]);
    }

    public function sheets(): array
    {
        $ranking = $this->ranking();
        $places = TopRanking::places(array_column($ranking, 'loans'));
        $detail = (new LoansReport(
            $this->request,
            (clone $this->loans)->whereIn('requested_by', array_column($ranking, 'user_id'))->orderBy('loan_date')->orderBy('id'),
        ))->sheets()[0];

        return [
            (new ReportSheet(
                'Ranking',
                ['Puesto', 'Profesor', 'Préstamos', 'Presenciales', 'Remotos', 'Rechazados', 'Unidades prestadas', 'Atrasados', 'Asignaturas', 'Carreras', 'Último préstamo'],
                array_map(fn (array $row, int $place): array => [
                    $place,
                    $row['name'],
                    $row['loans'],
                    $row['direct'],
                    $row['remote'],
                    $row['rejected'],
                    $row['units'],
                    $row['overdue'],
                    $row['subjects'],
                    $row['careers'],
                    $row['last_date'],
                ], $ranking, $places),
                fn (array $row): array => $row,
                [0 => XlsxWriter::INTEGER, 2 => XlsxWriter::INTEGER, 3 => XlsxWriter::INTEGER, 4 => XlsxWriter::INTEGER, 5 => XlsxWriter::INTEGER, 6 => XlsxWriter::INTEGER, 7 => XlsxWriter::INTEGER, 10 => XlsxWriter::DATE],
                [8, 28, 10, 12, 10, 11, 12, 10, 40, 34, 14],
            ))->summarized([], null, ['Préstamos', 'Presenciales', 'Remotos', 'Rechazados', 'Unidades prestadas', 'Atrasados']),
            $detail->summarized([]),
        ];
    }

    /**
     * Cifras por profesor, ordenadas por préstamos, unidades y nombre, cortadas en `top`.
     *
     * @return list<array{user_id: int, name: string, loans: int, direct: int, remote: int, rejected: int, units: int, overdue: int, subjects: ?string, careers: ?string, last_date: ?Carbon}>
     */
    private function ranking(): array
    {
        if ($this->ranking !== null) {
            return $this->ranking;
        }

        $rejected = Loan::STATUS_REJECTED;
        $base = fn () => (clone $this->loans)->reorder();

        $stats = $base()->toBase()
            ->select('requested_by')
            ->selectRaw('count(*) filter (where status <> ?) as loans', [$rejected])
            ->selectRaw('count(*) filter (where status <> ? and type = ?) as direct', [$rejected, Loan::TYPE_DIRECT])
            ->selectRaw('count(*) filter (where status <> ? and type = ?) as remote', [$rejected, Loan::TYPE_REMOTE])
            ->selectRaw('count(*) filter (where status = ?) as rejected', [$rejected])
            ->selectRaw("string_agg(distinct nullif(trim(subject), ''), ', ' order by nullif(trim(subject), '')) filter (where status <> ?) as subjects", [$rejected])
            ->selectRaw('max(loan_date) filter (where status <> ?) as last_date', [$rejected])
            ->groupBy('requested_by')
            ->get();

        $overdue = $base()->overdue()->toBase()->groupBy('requested_by')
            ->selectRaw('requested_by, count(*) as total')->pluck('total', 'requested_by');

        $units = DB::table('loan_item_units')
            ->join('loan_items', 'loan_items.id', '=', 'loan_item_units.loan_item_id')
            ->join('loans', 'loans.id', '=', 'loan_items.loan_id')
            ->whereIn('loans.id', $base()->where('status', '!=', $rejected)->select('loans.id'))
            ->groupBy('loans.requested_by')
            ->selectRaw('loans.requested_by, count(*) as total')
            ->pluck('total', 'requested_by');

        $careerNames = Career::query()->pluck('name', 'id');
        $careers = $base()->toBase()->where('status', '!=', $rejected)->whereNotNull('career_id')->distinct()->get(['requested_by', 'career_id'])
            ->groupBy('requested_by')
            ->map(fn ($pairs) => $pairs->map(fn (object $p) => $careerNames[$p->career_id] ?? null)->filter()->sort()->implode(', ') ?: null);

        $names = User::query()->whereIn('id', $stats->pluck('requested_by'))->pluck('name', 'id');

        $rows = $stats->map(fn (object $s): array => [
            'user_id' => (int) $s->requested_by,
            'name' => (string) ($names[$s->requested_by] ?? ''),
            'loans' => (int) $s->loans,
            'direct' => (int) $s->direct,
            'remote' => (int) $s->remote,
            'rejected' => (int) $s->rejected,
            'units' => (int) ($units[$s->requested_by] ?? 0),
            'overdue' => (int) ($overdue[$s->requested_by] ?? 0),
            'subjects' => $s->subjects,
            'careers' => $careers[$s->requested_by] ?? null,
            'last_date' => $s->last_date !== null ? Carbon::parse($s->last_date) : null,
        ])->sort(fn (array $a, array $b) => [$b['loans'], $b['units'], $a['name']] <=> [$a['loans'], $a['units'], $b['name']])->values()->all();

        return $this->ranking = TopRanking::cut($rows, $this->request);
    }
}
