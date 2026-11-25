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
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Arma y descarga un reporte Excel (docs/reportes-excel.md): lee cada hoja hasta el tope de filas,
 * agrega la hoja «Info» (reporte, usuario, fecha, filtros, filas y si se cortó) y, si el reporte
 * tiene datos personales, registra la exportación en `audit_logs`.
 */
class ReportExporter
{
    /** Tope de filas por hoja: un reporte no debe cargar tablas completas en memoria. */
    public const MAX_ROWS = 10000;

    public const TRUNCATED_HEADER = 'X-Report-Truncated';

    public function __construct(private readonly int $maxRows = self::MAX_ROWS) {}

    public function download(Report $report, Request $request): Response
    {
        [$sheets, $counts, $truncated] = $this->collect($report);
        $sheets[] = $this->infoSheet($report, $request->user(), $counts, $truncated);

        if ($report->audited()) {
            $this->audit($report, $request, array_sum($counts));
        }

        $xlsx = XlsxWriter::workbook($sheets);
        $filename = $report->filename().'-'.now()->format('Ymd-His').'.xlsx';

        return response($xlsx, 200, [
            'Content-Type' => XlsxWriter::MIME,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($xlsx),
            self::TRUNCATED_HEADER => $truncated !== [] ? '1' : '0',
        ]);
    }

    /**
     * @return array{0: list<XlsxSheet>, 1: array<string, int>, 2: list<string>}
     */
    private function collect(Report $report): array
    {
        $sheets = [];
        $counts = [];
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
            $sheets[] = new XlsxSheet($sheet->name, $headers, $rows, $types, $widths);
            $counts[$sheet->name] = count($rows);
            if ($cut) {
                $truncated[] = $sheet->name;
            }
        }

        return [$sheets, $counts, $truncated];
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

    private function audit(Report $report, Request $request, int $rows): void
    {
        AuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => AuditLog::REPORT_EXPORT_ACTION,
            'auditable_type' => AuditLog::REPORT_TYPE,
            'auditable_id' => 0,
            'old_values' => null,
            'new_values' => ['report' => $report->filename(), 'filters' => $report->filters(), 'rows' => $rows],
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, '') ?: null,
        ]);
    }
}
