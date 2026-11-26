<?php

namespace App\Reports;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\XlsxSheet;
use App\Support\XlsxWriter;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Arma y descarga un reporte Excel (docs/reportes-excel.md) o su vista previa en JSON (docs/centro-reportes.md):
 * lee cada hoja hasta el tope de filas, agrega «Resumen» (si alguna hoja lo pide) e «Info» (reporte, usuario,
 * alcance, fecha, filtros, filas y si se cortó) y registra cada descarga en `audit_logs` (la vista previa no).
 */
class ReportExporter
{
    /** Tope de filas por hoja: un reporte no debe cargar tablas completas en memoria. */
    public const MAX_ROWS = 10000;

    /** Filas por hoja en la vista previa. */
    public const PREVIEW_ROWS = 20;

    public const TRUNCATED_HEADER = 'X-Report-Truncated';

    private const DEFAULT_SCOPE = 'Todo lo visible para el rol';

    public function __construct(private readonly int $maxRows = self::MAX_ROWS) {}

    /** Vista previa en JSON con `preview=1`; si no, el `.xlsx`. */
    public function respond(Report $report, Request $request): Response|JsonResponse
    {
        return $request->boolean('preview') ? $this->preview($report, $request) : $this->download($report, $request);
    }

    public function download(Report $report, Request $request): Response
    {
        $book = $this->build($report, $request->user());
        $this->audit($report, $request, $book['rows']);

        $xlsx = XlsxWriter::workbook($book['sheets']);
        $filename = $this->filename($report);

        return response($xlsx, 200, [
            'Content-Type' => XlsxWriter::MIME,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($xlsx),
            self::TRUNCATED_HEADER => $book['truncated'] !== [] ? '1' : '0',
        ]);
    }

    /**
     * Las mismas hojas que la descarga, con las primeras filas de cada una (docs/centro-reportes.md, decisión 4).
     */
    public function preview(Report $report, Request $request): JsonResponse
    {
        $book = $this->build($report, $request->user());
        $info = array_pop($book['sheets']);

        return response()->json([
            'title' => $report->title(),
            'filename' => $this->filename($report),
            'truncated' => $book['truncated'] !== [],
            'sheets' => array_map(fn (XlsxSheet $sheet) => $this->previewSheet($sheet, in_array($sheet->name, $book['truncated'], true)), $book['sheets']),
            'info' => array_map(fn (array $row) => array_map($this->jsonValue(...), $row), [...$info->rows]),
        ]);
    }

    private function filename(Report $report): string
    {
        return $report->filename().'-'.now()->format('Ymd-His').'.xlsx';
    }

    /**
     * Hojas de datos, «Resumen» e «Info» del libro, con las filas totales y las hojas cortadas.
     *
     * @return array{sheets: list<XlsxSheet>, rows: int, truncated: list<string>}
     */
    private function build(Report $report, ?User $user): array
    {
        [$collected, $truncated] = $this->collect($report);

        $sheets = [];
        $counts = [];
        foreach ($collected as $c) {
            $sheets[] = new XlsxSheet($c['sheet']->name, $c['headers'], $c['rows'], $c['types'], $c['widths'], totals: $c['totals']);
            $counts[$c['sheet']->name] = count($c['rows']);
        }
        if ($summary = $this->summarySheet($collected)) {
            $sheets[] = $summary;
        }
        $sheets[] = $this->infoSheet($report, $user, $counts, $truncated);

        return ['sheets' => $sheets, 'rows' => array_sum($counts), 'truncated' => $truncated];
    }

    /**
     * @return array{0: list<array{sheet: ReportSheet, headers: list<string>, types: array<int, string>, widths: array<int, float|int>, totals: list<int>, rows: list<list<mixed>>}>, 1: list<string>}
     */
    private function collect(Report $report): array
    {
        $collected = [];
        $truncated = [];

        foreach ($report->sheets() as $sheet) {
            $rows = [];
            $cut = false;
            $source = $sheet->source instanceof Builder ? $sheet->source->lazy(500) : $sheet->source;

            foreach ($source as $record) {
                $produced = $sheet->expand ? ($sheet->map)($record) : [($sheet->map)($record)];
                foreach ($produced as $row) {
                    if (count($rows) >= $this->maxRows) {
                        $cut = true;
                        break 2;
                    }
                    $rows[] = $this->withYear(array_values($row), $sheet->yearOf);
                }
            }

            [$headers, $types, $widths] = $this->columns($sheet);
            $totals = array_values(array_filter(array_map(fn (string $h) => array_search($h, $headers, true), $sheet->totals), 'is_int'));
            $collected[] = ['sheet' => $sheet, 'headers' => $headers, 'types' => $types, 'widths' => $widths, 'totals' => $totals, 'rows' => $rows];
            if ($cut) {
                $truncated[] = $sheet->name;
            }
        }

        return [$collected, $truncated];
    }

    /**
     * Hoja «Resumen»: por cada cabecera pedida, cuántas filas tiene cada valor (y la suma del monto), desde las
     * filas ya filtradas de la hoja, así cuadra con el detalle. Con varias hojas resumidas, la primera columna dice cuál.
     *
     * @param  list<array{sheet: ReportSheet, headers: list<string>, types: array<int, string>, widths: array<int, float|int>, totals: list<int>, rows: list<list<mixed>>}>  $collected
     */
    private function summarySheet(array $collected): ?XlsxSheet
    {
        $summarized = array_values(array_filter($collected, fn ($c) => $c['sheet']->summary !== []));
        if ($summarized === []) {
            return null;
        }
        $several = count($summarized) > 1;
        $withAmount = collect($summarized)->contains(fn ($c) => $c['sheet']->summaryAmount !== null);

        $rows = [];
        foreach ($summarized as $c) {
            $amountAt = $c['sheet']->summaryAmount !== null ? array_search($c['sheet']->summaryAmount, $c['headers'], true) : false;
            foreach ($c['sheet']->summary as $groupBy) {
                $at = array_search($groupBy, $c['headers'], true);
                if ($at === false) {
                    continue;
                }
                $groups = [];
                foreach ($c['rows'] as $row) {
                    $value = $row[$at] ?? null;
                    $key = $value === null || $value === '' ? '(sin dato)' : (string) $value;
                    $groups[$key] ??= [0, 0];
                    $groups[$key][0]++;
                    if ($amountAt !== false && is_numeric($row[$amountAt] ?? null)) {
                        $groups[$key][1] += $row[$amountAt] + 0;
                    }
                }
                uksort($groups, fn ($a, $b) => [$groups[$b][0], $a] <=> [$groups[$a][0], $b]);
                foreach ($groups as $value => [$count, $amount]) {
                    $rows[] = [
                        ...($several ? [$c['sheet']->name] : []),
                        $groupBy,
                        $value,
                        $count,
                        ...($withAmount ? [$amountAt !== false ? $amount : null] : []),
                    ];
                }
            }
        }

        $headers = [...($several ? ['Hoja'] : []), 'Agrupado por', 'Valor', 'Cantidad', ...($withAmount ? ['Monto'] : [])];
        $offset = $several ? 1 : 0;
        $types = [$offset + 2 => XlsxWriter::INTEGER] + ($withAmount ? [$offset + 3 => XlsxWriter::MONEY] : []);
        $widths = [...($several ? [18] : []), 18, 36, 10, ...($withAmount ? [16] : [])];

        return new XlsxSheet('Resumen', $headers, $rows, $types, $widths);
    }

    /**
     * Una hoja de la vista previa: las primeras filas, el total y la fila de totales con el mismo cálculo del `.xlsx`.
     *
     * @return array<string, mixed>
     */
    private function previewSheet(XlsxSheet $sheet, bool $truncated): array
    {
        $rows = [...$sheet->rows];
        $types = array_map(fn ($i) => $sheet->types[$i] ?? XlsxWriter::TEXT, array_keys($sheet->headers));
        $totals = null;
        if ($sheet->totals !== [] && $rows !== []) {
            $totals = array_fill(0, count($sheet->headers), null);
            foreach ($sheet->totals as $i) {
                $totals[$i] = array_sum(array_map(fn ($r) => is_numeric($r[$i] ?? null) ? $r[$i] + 0 : 0, $rows));
            }
            $label = 0;
            while (in_array($label, $sheet->totals, true)) {
                $label++;
            }
            $totals[$label] = 'Total';
        }

        return [
            'name' => $sheet->name,
            'headers' => $sheet->headers,
            'types' => $types,
            'rows' => array_map(fn (array $row) => array_map(fn ($i) => $this->jsonValue($row[$i] ?? null, $types[$i] ?? null), array_keys($sheet->headers)), array_slice($rows, 0, self::PREVIEW_ROWS)),
            'total_rows' => count($rows),
            'totals' => $totals,
            'truncated' => $truncated,
        ];
    }

    /**
     * Valor de una celda en JSON: fechas en ISO (solo la fecha si la columna es `date`), números como número
     * (los `decimal` de Eloquent llegan como texto, «12000000.00») y el resto tal cual.
     */
    private function jsonValue(mixed $value, ?string $type = null): mixed
    {
        if ($value instanceof DateTimeInterface) {
            $local = DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone(date_default_timezone_get()));

            return $type === XlsxWriter::DATE ? $local->format('Y-m-d') : $local->format(DATE_ATOM);
        }

        if (is_string($value) && is_numeric($value) && in_array($type, [XlsxWriter::MONEY, XlsxWriter::INTEGER, XlsxWriter::YEAR], true)) {
            return $value + 0;
        }

        return is_bool($value) ? ($value ? 'sí' : 'no') : $value;
    }

    /**
     * Cabeceras, tipos y anchos de la hoja con la columna «Año» insertada tras `yearOf`; toda cabecera
     * «Año…» o «ID» va como `YEAR` (sin separador de miles: con `INTEGER` Excel muestra «2.026»).
     *
     * @return array{0: list<string>, 1: array<int, string>, 2: array<int, float|int>}
     */
    private function columns(ReportSheet $sheet): array
    {
        $headers = $sheet->headers;
        $types = $sheet->types;
        $widths = $sheet->widths;

        if ($sheet->yearOf !== null) {
            $at = $sheet->yearOf + 1;
            array_splice($headers, $at, 0, ['Año']);
            $shift = fn (array $byIndex): array => collect($byIndex)->mapWithKeys(fn ($v, $i) => [$i >= $at ? $i + 1 : $i => $v])->all();
            $types = $shift($types);
            $widths = $shift($widths) + [$at => 7];
        }

        foreach ($headers as $i => $header) {
            if ($header === 'ID' || preg_match('/^Año\b/u', $header)) {
                $types[$i] = XlsxWriter::YEAR;
            }
        }

        return [$headers, $types, $widths];
    }

    /**
     * Inserta el año local (Chile) de la fecha de la columna `$yearOf` justo después de ella.
     *
     * @param  list<mixed>  $row
     * @return list<mixed>
     */
    private function withYear(array $row, ?int $yearOf): array
    {
        if ($yearOf === null) {
            return $row;
        }

        $date = $row[$yearOf] ?? null;
        if (is_string($date) && $date !== '') {
            try {
                $date = new DateTimeImmutable($date);
            } catch (\Exception) {
                $date = null;
            }
        }
        $year = $date instanceof DateTimeInterface
            ? (int) DateTimeImmutable::createFromInterface($date)->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y')
            : null;
        array_splice($row, $yearOf + 1, 0, [$year]);

        return $row;
    }

    /**
     * @param  array<string, int>  $counts
     * @param  list<string>  $truncated
     */
    private function infoSheet(Report $report, ?User $user, array $counts, array $truncated): XlsxSheet
    {
        $filters = $report->filters();
        $rows = [
            ['Reporte', $report->title()],
            ['Generado por', $user ? trim($user->name.' ('.$user->email.')') : null],
            ['Rol', $user?->role],
            ['Alcance', $report instanceof ScopedReport ? $report->scope($user) : self::DEFAULT_SCOPE],
            ['Fecha', now()],
            ['Filtros', $filters === [] ? 'Ninguno: reporte completo' : collect($filters)->map(fn ($v, $k) => "{$k}: {$v}")->implode('; ')],
        ];
        foreach ($counts as $name => $count) {
            $rows[] = ["Filas en «{$name}»", $count];
        }
        foreach ($truncated as $name) {
            $rows[] = ['Aviso', 'La hoja «'.$name.'» se cortó en '.number_format($this->maxRows, 0, ',', '.').' filas: acota los filtros para ver el resto.'];
        }

        return new XlsxSheet('Info', ['Dato', 'Valor'], $rows, [1 => XlsxWriter::DATETIME], [24, 90], filter: false);
    }

    /**
     * Toda descarga queda en `audit_logs` (docs/centro-reportes.md, decisión 12): `path` y `query` permiten
     * repetirla desde «Mis descargas»; `personal` marca los reportes con RUN, correo o IP.
     */
    private function audit(Report $report, Request $request, int $rows): void
    {
        $query = collect($request->query())->except(['preview'])->all();

        AuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => AuditLog::REPORT_EXPORT_ACTION,
            'auditable_type' => AuditLog::REPORT_TYPE,
            'auditable_id' => 0,
            'old_values' => null,
            'new_values' => [
                'report' => $report->filename(),
                'title' => $report->title(),
                'filters' => $report->filters(),
                'rows' => $rows,
                'path' => '/'.ltrim((string) preg_replace('#^api/#', '', $request->path()), '/'),
                'query' => $query,
                'personal' => $report->audited(),
            ],
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, '') ?: null,
        ]);
    }
}
