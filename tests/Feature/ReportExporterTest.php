<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Reports\Report;
use App\Reports\ReportExporter;
use App\Reports\ReportSheet;
use App\Support\XlsxWriter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ReadsXlsx;
use Tests\TestCase;
use Tests\Unit\XlsxWriterTest;

/**
 * Hoja «Info», tope de filas, hojas expandidas y auditoría de las exportaciones (docs/reportes-excel.md).
 */
class ReportExporterTest extends TestCase
{
    use DatabaseTransactions;
    use ReadsXlsx;

    private function report(bool $audited, array $filters = []): Report
    {
        return new class($audited, $filters) implements Report
        {
            public function __construct(private bool $audited, private array $filters) {}

            public function title(): string
            {
                return 'Prueba';
            }

            public function filename(): string
            {
                return 'prueba';
            }

            public function audited(): bool
            {
                return $this->audited;
            }

            public function filters(): array
            {
                return $this->filters;
            }

            public function sheets(): array
            {
                return [
                    new ReportSheet('Pedidos', ['Código', 'Total', 'Fecha'], [['SC-1', 1000, '2026-03-10'], ['SC-2', 2000, null], ['SC-3', 3000, null]],
                        fn (array $r) => $r, [1 => XlsxWriter::MONEY, 2 => XlsxWriter::DATE]),
                    ReportSheet::expanded('Ítems', ['Pedido', 'Ítem'], [['SC-1', ['a', 'b']], ['SC-2', ['c']]],
                        fn (array $r) => array_map(fn ($i) => [$r[0], $i], $r[1])),
                ];
            }
        };
    }

    public function test_info_sheet_expanded_rows_truncation_and_audit(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true, 'name' => 'Ana Admin']);
        Sanctum::actingAs($user);
        $request = Request::create('/api/x/export');
        $request->setUserResolver(fn () => $user);

        $response = (new ReportExporter(2))->download($this->report(true, ['Estado' => 'Activo']), $request);
        $this->assertSame('1', $response->headers->get(ReportExporter::TRUNCATED_HEADER));

        $xlsx = (string) $response->getContent();
        $this->assertSame(['Pedidos', 'Ítems', 'Info'], array_keys($this->readXlsx($xlsx)));
        $this->assertCount(2, $this->xlsxRecords($xlsx, 'Pedidos'));
        $this->assertSame([['Pedido' => 'SC-1', 'Ítem' => 'a'], ['Pedido' => 'SC-1', 'Ítem' => 'b']], $this->xlsxRecords($xlsx, 'Ítems'), 'tope también en filas expandidas');

        $info = $this->xlsxInfo($xlsx);
        $this->assertSame('Prueba', $info['Reporte']);
        $this->assertStringContainsString('Ana Admin', $info['Generado por']);
        $this->assertSame(User::ROLE_ADMIN, $info['Rol']);
        $this->assertSame('Estado: Activo', $info['Filtros']);
        $this->assertSame(2, $info['Filas en «Ítems»']);

        $log = AuditLog::where('user_id', $user->id)->where('action', AuditLog::REPORT_EXPORT_ACTION)->sole();
        $this->assertSame(AuditLog::REPORT_TYPE, $log->auditable_type);
        $this->assertEquals(['report' => 'prueba', 'title' => 'Prueba', 'filters' => ['Estado' => 'Activo'], 'rows' => 4, 'path' => '/x/export', 'query' => [], 'personal' => true], $log->new_values);

        $this->getJson('/api/audit-logs?auditable_type=report')->assertOk()->assertJsonFragment(['action' => AuditLog::REPORT_EXPORT_ACTION]);
    }

    public function test_every_download_is_audited_but_not_the_preview(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_DIRECTOR, 'is_active' => true]);
        $request = Request::create('/api/x/export', 'GET', ['estado' => 'activo']);
        $request->setUserResolver(fn () => $user);

        $response = (new ReportExporter)->download($this->report(false), $request);
        $this->assertSame('0', $response->headers->get(ReportExporter::TRUNCATED_HEADER));
        $this->assertSame('Ninguno: reporte completo', $this->xlsxInfo((string) $response->getContent())['Filtros']);
        $this->assertSame('Todo lo visible para el rol', $this->xlsxInfo((string) $response->getContent())['Alcance']);
        $log = AuditLog::where('user_id', $user->id)->sole();
        $this->assertSame(['estado' => 'activo'], $log->new_values['query']);
        $this->assertFalse($log->new_values['personal']);

        $preview = Request::create('/api/x/export', 'GET', ['preview' => '1']);
        $preview->setUserResolver(fn () => $user);
        (new ReportExporter)->respond($this->report(true), $preview);
        $this->assertSame(1, AuditLog::where('user_id', $user->id)->count(), 'la vista previa no se audita');
    }

    public function test_preview_has_the_same_sheets_summary_and_totals_as_the_workbook(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_DIRECTOR, 'is_active' => true]);
        $report = new class implements Report
        {
            public function title(): string
            {
                return 'Gastos';
            }

            public function filename(): string
            {
                return 'gastos';
            }

            public function audited(): bool
            {
                return false;
            }

            public function filters(): array
            {
                return [];
            }

            public function sheets(): array
            {
                $rows = [];
                foreach (range(1, 25) as $n) {
                    $rows[] = ["F-{$n}", $n % 2 ? 'Pagada' : 'Pendiente', $n <= 20 ? 'Informática' : null, 1000 * $n, '2026-03-'.str_pad((string) min($n, 28), 2, '0', STR_PAD_LEFT)];
                }

                return [(new ReportSheet('Facturas', ['Folio', 'Estado', 'Carrera', 'Total', 'Fecha'], $rows, fn (array $r) => $r, [3 => XlsxWriter::MONEY, 4 => XlsxWriter::DATE], yearOf: 4))
                    ->summarized(['Estado', 'Carrera'], 'Total', ['Total'])];
            }
        };

        $request = Request::create('/api/x/export', 'GET', ['preview' => '1']);
        $request->setUserResolver(fn () => $user);
        $json = (new ReportExporter)->respond($report, $request)->getData(true);

        $this->assertSame('Gastos', $json['title']);
        $this->assertMatchesRegularExpression('/^gastos-\d{8}-\d{6}\.xlsx$/', $json['filename']);
        $this->assertSame(['Facturas', 'Resumen'], array_column($json['sheets'], 'name'));
        $facturas = $json['sheets'][0];
        $this->assertSame(['Folio', 'Estado', 'Carrera', 'Total', 'Fecha', 'Año'], $facturas['headers']);
        $this->assertSame(['text', 'text', 'text', 'money', 'date', 'year'], $facturas['types']);
        $this->assertCount(ReportExporter::PREVIEW_ROWS, $facturas['rows']);
        $this->assertSame(25, $facturas['total_rows']);
        $this->assertSame(['F-1', 'Pagada', 'Informática', 1000, '2026-03-01', 2026], $facturas['rows'][0]);
        $this->assertSame(['Total', null, null, 325000, null, null], $facturas['totals'], 'suma todas las filas, no solo las 20 de la vista previa');

        $resumen = collect($json['sheets'][1]['rows'])->map(fn ($r) => implode('|', $r))->all();
        $this->assertContains('Estado|Pagada|13|169000', $resumen);
        $this->assertContains('Estado|Pendiente|12|156000', $resumen);
        $this->assertContains('Carrera|Informática|20|210000', $resumen);
        $this->assertContains('Carrera|(sin dato)|5|115000', $resumen);
        $this->assertSame('Alcance', $json['info'][3][0]);

        // El .xlsx trae las mismas hojas y la fila de totales con SUBTOTAL fuera del autofiltro.
        $download = Request::create('/api/x/export');
        $download->setUserResolver(fn () => $user);
        $xlsx = (string) (new ReportExporter)->download($report, $download)->getContent();
        $this->assertSame(['Facturas', 'Resumen', 'Info'], array_keys($this->readXlsx($xlsx)));
        $sheet = XlsxWriterTest::unzip($xlsx)['xl/worksheets/sheet1.xml'];
        $this->assertStringContainsString('<c r="A27" t="inlineStr" s="6"><is><t xml:space="preserve">Total</t></is></c>', $sheet);
        $this->assertStringContainsString('<c r="D27" s="7"><f>SUBTOTAL(109,D2:D26)</f><v>325000</v></c>', $sheet);
        $this->assertStringContainsString('<autoFilter ref="A1:F26"/>', $sheet, 'los totales quedan fuera del autofiltro');
    }

    public function test_year_column_after_the_operation_date_and_years_without_thousands_separator(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_DIRECTOR, 'is_active' => true]);
        $request = Request::create('/api/x/export');
        $request->setUserResolver(fn () => $user);
        $report = new class implements Report
        {
            public function title(): string
            {
                return 'Años';
            }

            public function filename(): string
            {
                return 'anios';
            }

            public function audited(): bool
            {
                return false;
            }

            public function filters(): array
            {
                return [];
            }

            public function sheets(): array
            {
                return [new ReportSheet('Datos', ['ID', 'Fecha', 'Año presupuesto', 'Monto'], [
                    [1500, new \DateTimeImmutable('2025-12-31 23:30:00', new \DateTimeZone('America/Santiago')), 2026, 1990000],
                    [7, '2024-01-01 02:00:00', '2026', 5],
                    [8, null, null, 1],
                ], fn (array $r) => $r, [0 => XlsxWriter::INTEGER, 1 => XlsxWriter::DATETIME, 2 => XlsxWriter::INTEGER, 3 => XlsxWriter::MONEY], yearOf: 1)];
            }
        };

        $xlsx = (string) (new ReportExporter)->download($report, $request)->getContent();
        $rows = $this->xlsxRecords($xlsx, 'Datos');
        $this->assertSame(['ID', 'Fecha', 'Año', 'Año presupuesto', 'Monto'], array_keys($rows[0]), '«Año» justo después de la fecha');
        $this->assertSame(2025, $rows[0]['Año'], 'año local de Chile');
        $this->assertSame(2024, $rows[1]['Año'], 'también desde texto');
        $this->assertArrayNotHasKey('Año', $rows[2], 'sin fecha, sin año');

        $sheet = XlsxWriterTest::unzip($xlsx)['xl/worksheets/sheet1.xml'];
        // Años e ID como número General (sin estilo `#,##0`, que Excel muestra como «2.026»); el monto sigue en pesos.
        $this->assertStringContainsString('<c r="A2"><v>1500</v></c>', $sheet);
        $this->assertStringContainsString('<c r="C2"><v>2025</v></c>', $sheet);
        $this->assertStringContainsString('<c r="D2"><v>2026</v></c>', $sheet);
        $this->assertStringContainsString('<c r="D3"><v>2026</v></c>', $sheet, 'año desde texto numérico');
        $this->assertStringContainsString('<c r="E2" s="4"><v>1990000</v></c>', $sheet);
    }
}
