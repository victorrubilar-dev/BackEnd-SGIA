<?php

namespace App\Services;

use App\Models\Career;
use App\Models\EquipmentUnit;
use App\Models\Loan;
use App\Models\Product;
use App\Models\User;
use App\Support\DashboardPeriod;
use Carbon\Exceptions\InvalidFormatException;
use GraphQL\Error\UserError;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Métricas genéricas del panel (GraphQL `/api/metrics`, FU-06).
 *
 * Cada consulta combina entidad + métrica + dimensión (`groupBy`) + período y
 * se resuelve en filas {label, count, total?, secondary?, category?} que
 * `shape()` convierte en puntos según la métrica pedida:
 *
 *  - COUNT: cantidad de registros del grupo.
 *  - TOTAL: unidades (suma de cantidades); donde no hay unidades equivale a COUNT.
 *  - AVERAGE: unidades por registro; donde no hay unidades equivale a COUNT.
 *  - RATE: porcentaje del grupo sobre el total (suma 100).
 *
 * EQUIPMENT describe los productos de tipo `equipo` (inventario por categoría,
 * ubicación y condición) y SUPPLIES los de tipo `insumo` (consumo por carrera,
 * categoría y rotación). El nivel de carrera sale de `careers`, unida por
 * nombre con `users.area`.
 */
class MetricsService
{
    /** @var array<string, array<int, string>> */
    public const GROUP_BY = [
        'LOANS' => ['status', 'teacher', 'subject', 'item_type'],
        'SUPPLIES' => ['career', 'category', 'turnover'],
        'EQUIPMENT' => ['hardware_category', 'location', 'physical_condition'],
        'SCALE_MODELS' => ['hardware_category', 'location', 'physical_condition'],
        'ALERTS' => ['criticality', 'specialty_area'],
        'MAINTENANCE' => ['equipment', 'failure_type', 'workshop'],
        'CAREERS' => ['specialty', 'level'],
        'TEACHERS' => ['subject', 'department'],
    ];

    private const ENTITY_LABELS = [
        'LOANS' => 'Préstamos', 'SUPPLIES' => 'Insumos', 'EQUIPMENT' => 'Equipos', 'SCALE_MODELS' => 'Maquetas', 'ALERTS' => 'Alertas de stock',
        'MAINTENANCE' => 'Mantenciones', 'CAREERS' => 'Carreras', 'TEACHERS' => 'Docentes',
    ];

    /** @var array<string, array<string, string>> */
    private const VALUE_LABELS = [
        'criticality' => ['critical' => 'Crítica', 'warning' => 'Advertencia'],
        'failure_type' => ['leve' => 'Leve', 'media' => 'Media', 'critica' => 'Crítica'],
        'level' => Career::LEVELS,
    ];

    public function __construct(private readonly DashboardService $dashboard) {}

    /**
     * @return array<string, int|float>
     */
    public function executiveKpis(string $timeRange): array
    {
        if ($timeRange === 'CUSTOM') {
            throw new UserError('executiveKpis no admite CUSTOM; usa un período predefinido.');
        }

        $k = $this->dashboard->kpis(DashboardPeriod::resolve($timeRange));

        return [
            'activosDisponibles' => $k['assets']['available'],
            'activosEnPrestamo' => $k['assets']['on_loan'],
            'activosEnTaller' => $k['assets']['in_repair'],
            'activosDeBaja' => $k['assets']['written_off'],
            'prestamosActivosHoy' => $k['loans_today'],
            'tasaAtrasosPct' => $k['overdue_rate'],
            'alertasCriticasNoResueltas' => $k['critical_alerts'],
            'tasaRotacionMensualPct' => $k['rotation_rate'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input  MetricQueryInput
     * @return array<string, mixed> MetricResult
     */
    public function customMetric(array $input): array
    {
        $entity = $input['entity'];
        $metric = $input['metric'];
        $groupBy = ($input['groupBy'] ?? '') !== '' ? $input['groupBy'] : null;

        // Las plantillas del frontend usan `category` para EQUIPMENT.
        if (in_array($entity, ['EQUIPMENT', 'SCALE_MODELS'], true) && $groupBy === 'category') {
            $groupBy = 'hardware_category';
        }

        if ($groupBy !== null && ! in_array($groupBy, self::GROUP_BY[$entity], true)) {
            throw new UserError("groupBy \"{$groupBy}\" no es válido para {$entity}. Opciones: ".implode(', ', self::GROUP_BY[$entity]).'.');
        }

        $period = $this->period($input);
        $limit = max(1, min(50, (int) ($input['limit'] ?? 10)));

        $notes = [];

        if ($entity === 'LOANS' && $metric === 'RATE' && $groupBy === null) {
            $points = $this->loanPunctuality($period);
        } else {
            $data = match ($entity) {
                'LOANS' => $this->loans($groupBy, $period),
                'SUPPLIES' => $this->supplies($groupBy, $period),
                'EQUIPMENT' => $this->equipment($groupBy, $period, Product::TYPE_EQUIPMENT),
                'SCALE_MODELS' => $this->equipment($groupBy, $period, Product::TYPE_SCALE_MODEL),
                'ALERTS' => $this->alerts($groupBy, $period),
                'MAINTENANCE' => $this->maintenance($groupBy, $period),
                'CAREERS' => $this->careers($groupBy, $period),
                'TEACHERS' => $this->teachers($groupBy, $period),
            };

            if (! $data['hasTotal'] && in_array($metric, ['TOTAL', 'AVERAGE'], true)) {
                $notes[] = "{$metric} no aplica a {$entity}; se muestra el conteo";
            }

            $points = $this->shape($data, $metric, $limit);
        }

        return [
            'entity' => $entity,
            'metric' => $metric,
            'timeRange' => $input['timeRange'],
            'chartType' => $input['chartType'],
            'points' => $points,
            'summary' => $this->summary($entity, $metric, $groupBy, $period, $points, $notes),
        ];
    }

    private function period(array $input): DashboardPeriod
    {
        try {
            if ($input['timeRange'] === 'CUSTOM') {
                if (empty($input['dateFrom'])) {
                    throw new UserError('CUSTOM requiere dateFrom (y opcionalmente dateTo).');
                }

                return DashboardPeriod::resolve(null, $input['dateFrom'], $input['dateTo'] ?? null);
            }

            return DashboardPeriod::resolve($input['timeRange']);
        } catch (ValidationException $e) {
            throw new UserError(collect($e->errors())->flatten()->first());
        } catch (InvalidFormatException) {
            throw new UserError('dateFrom / dateTo deben tener formato YYYY-MM-DD.');
        }
    }

    /**
     * @param  array{rows: array<int, array<string, mixed>>, series: bool, hasTotal: bool}  $data
     * @return array<int, array<string, mixed>>
     */
    private function shape(array $data, string $metric, int $limit): array
    {
        $measure = fn (array $r) => $data['hasTotal'] ? (float) $r['total'] : (float) $r['count'];
        $base = array_sum(array_map($measure, $data['rows']));

        $points = array_map(function (array $r) use ($data, $metric, $measure, $base) {
            $value = match ($metric) {
                'COUNT' => (float) $r['count'],
                'TOTAL' => $measure($r),
                'AVERAGE' => $data['hasTotal'] ? ($r['count'] > 0 ? $r['total'] / $r['count'] : 0.0) : (float) $r['count'],
                'RATE' => $base > 0 ? $measure($r) / $base * 100 : 0.0,
            };

            return array_filter([
                'label' => (string) $r['label'],
                'value' => round($value, 1),
                'secondaryValue' => isset($r['secondary']) ? (float) $r['secondary'] : null,
                'category' => $r['category'] ?? null,
            ], fn ($v) => $v !== null);
        }, $data['rows']);

        if (! $data['series']) {
            usort($points, fn ($a, $b) => [$b['value'], $a['label']] <=> [$a['value'], $b['label']]);
            $points = array_slice($points, 0, $limit);
        }

        return $points;
    }

    /**
     * @param  array<int, array<string, mixed>>  $points
     * @param  array<int, string>  $notes
     */
    private function summary(string $entity, string $metric, ?string $groupBy, DashboardPeriod $period, array $points, array $notes): string
    {
        $parts = [
            self::ENTITY_LABELS[$entity].' · '.$metric.($groupBy ? " por {$groupBy}" : ''),
            "{$period->from->toDateString()} a {$period->to->toDateString()}",
            count($points).' punto(s)',
        ];

        return implode(' · ', array_merge($parts, $notes));
    }

    // ----- Préstamos ---------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function loans(?string $groupBy, DashboardPeriod $period): array
    {
        return match ($groupBy) {
            null => $this->series($this->loanDays($period), $period),
            'status' => $this->groups($this->loanGroups('loans.status', $period, includeRejected: true), 'status', true),
            'teacher' => $this->groups($this->loanGroups("coalesce(users.name, loans.borrower_name, 'Sin nombre')", $period), null, true),
            'subject' => $this->groups($this->loanGroups("coalesce(loans.subject, 'Sin asignatura')", $period), null, true),
            'item_type' => $this->groups($this->loanGroups("coalesce(products.area, 'Sin categoría')", $period), null, true),
        };
    }

    /**
     * Devuelto a tiempo / activo / atrasado sobre los préstamos entregados del período.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loanPunctuality(DashboardPeriod $period): array
    {
        $loans = Loan::where('status', Loan::STATUS_PROCESSED)
            ->whereBetween('loan_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->get(['id', 'loan_date', 'due_date', 'returned_at']);

        $buckets = ['Devuelto a tiempo' => 0, 'Activo' => 0, 'Atrasado' => 0];

        foreach ($loans as $loan) {
            $due = $loan->due_date ?? $loan->loan_date;

            if ($loan->returned_at !== null) {
                $buckets[$loan->returned_at->startOfDay()->lte($due) ? 'Devuelto a tiempo' : 'Atrasado']++;
            } else {
                $buckets[$due->lt(today()) ? 'Atrasado' : 'Activo']++;
            }
        }

        $total = max(1, $loans->count());

        return collect($buckets)->map(fn ($n, $label) => ['label' => $label, 'value' => round($n / $total * 100, 1)])->values()->all();
    }

    /**
     * @return array<string, array{count: int, total: int}>
     */
    private function loanDays(DashboardPeriod $period): array
    {
        return DB::table('loans')
            ->leftJoin('loan_items', 'loan_items.loan_id', '=', 'loans.id')
            ->where('loans.status', '!=', Loan::STATUS_REJECTED)
            ->whereBetween('loans.loan_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->groupBy('loans.loan_date')
            ->selectRaw('loans.loan_date as d, count(distinct loans.id) as c, coalesce(sum(loan_items.quantity), 0) as t')
            ->get()
            ->mapWithKeys(fn ($r) => [substr((string) $r->d, 0, 10) => ['count' => (int) $r->c, 'total' => (int) $r->t]])
            ->all();
    }

    /**
     * Préstamos agrupados por una expresión SQL (users, products y careers ya unidos).
     *
     * @return array<int, object>
     */
    private function loanGroups(string $labelExpr, DashboardPeriod $period, bool $includeRejected = false, string $countExpr = 'count(distinct loans.id)', ?string $secondaryExpr = null, string $extraWhere = '1=1'): array
    {
        $query = DB::table('loans')
            ->leftJoin('loan_items', 'loan_items.loan_id', '=', 'loans.id')
            ->leftJoin('products', 'products.id', '=', 'loan_items.product_id')
            ->leftJoin('users', 'users.id', '=', 'loans.requested_by')
            ->leftJoin('careers', 'careers.name', '=', 'users.area')
            ->whereBetween('loans.loan_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->whereRaw($extraWhere)
            ->groupBy(DB::raw($labelExpr))
            ->selectRaw("{$labelExpr} as label, {$countExpr} as c, coalesce(sum(loan_items.quantity), 0) as t".($secondaryExpr ? ", {$secondaryExpr} as s" : ''));

        if (! $includeRejected) {
            $query->where('loans.status', '!=', Loan::STATUS_REJECTED);
        }

        return $query->get()->all();
    }

    // ----- Insumos y equipos -------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function supplies(?string $groupBy, DashboardPeriod $period): array
    {
        $supply = "products.type = '".Product::TYPE_SUPPLY."'";
        $byItems = fn (string $expr) => $this->loanGroups($expr, $period, countExpr: 'count(loan_items.id)', extraWhere: $supply);

        return match ($groupBy) {
            null => $this->groups($this->loanGroups('products.name', $period, countExpr: 'count(loan_items.id)', secondaryExpr: 'max(products.quantity)', extraWhere: $supply), null, true, secondary: true),
            'career' => $this->groups($byItems("coalesce(users.area, 'Sin carrera')"), null, true),
            'category' => $this->groups($byItems("coalesce(products.area, 'Sin categoría')"), null, true),
            'turnover' => $this->turnover($period),
        };
    }

    /**
     * Insumos activos clasificados por rotación = prestado / (stock + prestado).
     *
     * @return array<string, mixed>
     */
    private function turnover(DashboardPeriod $period): array
    {
        $lent = DB::table('loan_items')
            ->join('loans', 'loans.id', '=', 'loan_items.loan_id')
            ->where('loans.status', '!=', Loan::STATUS_REJECTED)
            ->whereBetween('loans.loan_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->groupBy('loan_items.product_id')
            ->selectRaw('loan_items.product_id, sum(loan_items.quantity) as units');

        $products = DB::table('products')
            ->leftJoinSub($lent, 'l', 'l.product_id', '=', 'products.id')
            ->where('products.is_active', true)
            ->where('products.type', Product::TYPE_SUPPLY)
            ->selectRaw('products.quantity, coalesce(l.units, 0) as units')
            ->get();

        $buckets = ['Alta rotación' => [0, 0], 'Media rotación' => [0, 0], 'Baja rotación' => [0, 0], 'Sin movimiento' => [0, 0]];

        foreach ($products as $p) {
            $ratio = $p->units > 0 ? $p->units / ($p->quantity + $p->units) : 0;
            $key = match (true) {
                $p->units == 0 => 'Sin movimiento',
                $ratio >= 0.5 => 'Alta rotación',
                $ratio >= 0.2 => 'Media rotación',
                default => 'Baja rotación',
            };
            $buckets[$key][0]++;
            $buckets[$key][1] += (int) $p->units;
        }

        return [
            'rows' => collect($buckets)->map(fn ($v, $label) => ['label' => $label, 'count' => $v[0], 'total' => $v[1]])->values()->all(),
            'series' => true,
            'hasTotal' => true,
        ];
    }

    /**
     * Equipos (`equipo`) o maquetas (`maqueta`): mismas métricas y agrupaciones. Ubicación y
     * condición física cuentan unidades (`equipment_units`), no productos.
     *
     * @return array<string, mixed>
     */
    private function equipment(?string $groupBy, DashboardPeriod $period, string $type): array
    {
        $products = fn () => DB::table('products')->where('products.is_active', true)->where('products.type', $type);
        $filter = "products.type = '{$type}'";

        return match ($groupBy) {
            null => $this->groups($this->loanGroups('products.name', $period, countExpr: 'count(loan_items.id)', secondaryExpr: 'max(products.quantity)', extraWhere: $filter), null, true, secondary: true),
            'hardware_category' => $this->groups(
                $products()->groupBy(DB::raw("coalesce(area, 'Sin categoría')"))
                    ->selectRaw("coalesce(area, 'Sin categoría') as label, count(*) as c, coalesce(sum(quantity), 0) as t")->get()->all(),
                null, true
            ),
            'location' => $this->groups(
                $this->activeUnits($type)
                    ->where('equipment_units.status', '!=', EquipmentUnit::STATUS_WRITTEN_OFF)
                    ->join('cajones', 'cajones.id', '=', 'equipment_units.cajon_id')
                    ->join('locations', 'locations.id', '=', 'cajones.location_id')
                    ->groupBy('locations.nombre')
                    ->selectRaw('locations.nombre as label, count(*) as c, count(*) as t')->get()->all(),
                null, true
            ),
            'physical_condition' => $this->physicalCondition($type),
        };
    }

    /**
     * Unidades de productos activos del tipo indicado.
     */
    private function activeUnits(string $type): Builder
    {
        return DB::table('equipment_units')
            ->join('products', 'products.id', '=', 'equipment_units.product_id')
            ->where('products.is_active', true)
            ->where('products.type', $type);
    }

    /**
     * Unidades por estado: disponible, en taller y dado de baja.
     *
     * @return array<string, mixed>
     */
    private function physicalCondition(string $type): array
    {
        $counts = $this->activeUnits($type)
            ->groupBy('equipment_units.status')
            ->selectRaw('equipment_units.status as status, count(*) as total')
            ->pluck('total', 'status');

        $row = fn (string $label, string $status) => ['label' => $label, 'count' => (int) ($counts[$status] ?? 0), 'total' => (int) ($counts[$status] ?? 0)];

        return [
            'rows' => [
                $row('Disponible', EquipmentUnit::STATUS_AVAILABLE),
                $row('En taller', EquipmentUnit::STATUS_IN_REPAIR),
                $row('Dado de baja', EquipmentUnit::STATUS_WRITTEN_OFF),
            ],
            'series' => true,
            'hasTotal' => true,
        ];
    }

    // ----- Alertas y mantenciones -------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function alerts(?string $groupBy, DashboardPeriod $period): array
    {
        $base = fn () => DB::table('stock_alerts')
            ->whereBetween('stock_alerts.created_at', [$period->from->startOfDay(), $period->to->endOfDay()]);

        return match ($groupBy) {
            null => $this->series($this->daily($base(), 'stock_alerts.created_at'), $period, hasTotal: false),
            'criticality' => $this->groups($base()->groupBy('alert_type')->selectRaw('alert_type as label, count(*) as c')->get()->all(), 'criticality', false),
            'specialty_area' => $this->groups(
                $base()->leftJoin('products', 'products.id', '=', 'stock_alerts.product_id')
                    ->groupBy(DB::raw("coalesce(products.area, 'Sin área')"))
                    ->selectRaw("coalesce(products.area, 'Sin área') as label, count(*) as c")->get()->all(),
                null, false
            ),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function maintenance(?string $groupBy, DashboardPeriod $period): array
    {
        $base = fn () => DB::table('incident_reports')
            ->whereBetween('incident_reports.created_at', [$period->from->startOfDay(), $period->to->endOfDay()]);

        return match ($groupBy) {
            null => $this->series($this->daily($base(), 'incident_reports.created_at'), $period, hasTotal: false),
            'failure_type' => $this->groups($base()->groupBy('severity')->selectRaw('severity as label, count(*) as c')->get()->all(), 'failure_type', false),
            'equipment' => $this->groups(
                $base()->join('products', 'products.id', '=', 'incident_reports.product_id')
                    ->groupBy('products.id', 'products.name')->selectRaw('products.name as label, count(*) as c')->get()->all(),
                null, false
            ),
            'workshop' => $this->groups(
                $base()->leftJoin('products', 'products.id', '=', 'incident_reports.product_id')
                    ->leftJoin('cajones', 'cajones.id', '=', 'products.cajon_id')
                    // Si el informe es de una unidad, la sala es la de su cajón.
                    ->leftJoin('equipment_units', 'equipment_units.id', '=', 'incident_reports.equipment_unit_id')
                    ->leftJoin('cajones as unit_cajones', 'unit_cajones.id', '=', 'equipment_units.cajon_id')
                    ->leftJoin('locations', fn ($j) => $j->whereRaw('locations.id = coalesce(unit_cajones.location_id, products.location_id, cajones.location_id)'))
                    ->groupBy(DB::raw("coalesce(locations.nombre, 'Sin ubicación')"))
                    ->selectRaw("coalesce(locations.nombre, 'Sin ubicación') as label, count(*) as c")->get()->all(),
                null, false
            ),
        };
    }

    // ----- Carreras y docentes ----------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function careers(?string $groupBy, DashboardPeriod $period): array
    {
        return match ($groupBy) {
            'level' => $this->groups($this->loanGroups("coalesce(careers.level, 'Sin nivel')", $period), 'level', true),
            default => $this->groups($this->loanGroups("coalesce(users.area, 'Sin carrera')", $period), null, true),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function teachers(?string $groupBy, DashboardPeriod $period): array
    {
        $teachers = "users.role = '".User::ROLE_TEACHER."'";

        $expr = match ($groupBy) {
            'subject' => "coalesce(loans.subject, 'Sin asignatura')",
            'department' => "coalesce(users.area, 'Sin departamento')",
            default => 'users.name',
        };

        return $this->groups($this->loanGroups($expr, $period, extraWhere: $teachers), null, true);
    }

    // ----- Utilidades -------------------------------------------------------

    /**
     * Filas {label, count, total?, secondary?} a partir de resultados con alias label/c/t/s.
     *
     * @param  array<int, object>  $results
     * @return array{rows: array<int, array<string, mixed>>, series: bool, hasTotal: bool}
     */
    private function groups(array $results, ?string $labelMap, bool $hasTotal, bool $secondary = false): array
    {
        $rows = array_map(function ($r) use ($labelMap, $hasTotal, $secondary) {
            $label = $labelMap !== null ? (self::VALUE_LABELS[$labelMap][$r->label] ?? $this->statusLabel($labelMap, $r->label)) : $r->label;

            return array_filter([
                'label' => $label,
                'count' => (int) $r->c,
                'total' => $hasTotal ? (int) $r->t : null,
                'secondary' => $secondary ? (int) $r->s : null,
            ], fn ($v) => $v !== null);
        }, $results);

        return ['rows' => $rows, 'series' => false, 'hasTotal' => $hasTotal];
    }

    private function statusLabel(string $map, string $value): string
    {
        return $map === 'status' ? LoanStateMachine::labelFor($value) : $value;
    }

    /**
     * @return array<string, array{count: int, total: int}>
     */
    private function daily($query, string $column): array
    {
        return $query->groupBy(DB::raw("date({$column})"))
            ->selectRaw("date({$column}) as d, count(*) as c")
            ->get()
            ->mapWithKeys(fn ($r) => [(string) $r->d => ['count' => (int) $r->c, 'total' => 0]])
            ->all();
    }

    /**
     * Serie temporal: semanas ("Semana N") hasta 31 días, meses ("YYYY-MM") si es más largo.
     *
     * @param  array<string, array{count: int, total: int}>  $days
     * @return array{rows: array<int, array<string, mixed>>, series: bool, hasTotal: bool}
     */
    private function series(array $days, DashboardPeriod $period, bool $hasTotal = true): array
    {
        $weekly = $period->from->diffInDays($period->to) <= 31;
        $buckets = [];

        for ($d = $period->from; $d->lte($period->to); $d = $d->addDay()) {
            $key = $weekly ? 'Semana '.(intdiv((int) $period->from->diffInDays($d), 7) + 1) : $d->format('Y-m');
            $buckets[$key] ??= ['label' => $key, 'count' => 0, 'total' => 0];
            $buckets[$key]['count'] += $days[$d->toDateString()]['count'] ?? 0;
            $buckets[$key]['total'] += $days[$d->toDateString()]['total'] ?? 0;
        }

        return ['rows' => array_values($buckets), 'series' => true, 'hasTotal' => $hasTotal];
    }
}
