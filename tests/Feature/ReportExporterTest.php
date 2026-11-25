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
        $this->assertEquals(['report' => 'prueba', 'filters' => ['Estado' => 'Activo'], 'rows' => 4], $log->new_values);

        $this->getJson('/api/audit-logs?auditable_type=report')->assertOk()->assertJsonFragment(['action' => AuditLog::REPORT_EXPORT_ACTION]);
    }

    public function test_reports_without_personal_data_are_not_audited(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_DIRECTOR, 'is_active' => true]);
        $request = Request::create('/api/x/export');
        $request->setUserResolver(fn () => $user);

        $response = (new ReportExporter)->download($this->report(false), $request);
        $this->assertSame('0', $response->headers->get(ReportExporter::TRUNCATED_HEADER));
        $this->assertSame('Ninguno: reporte completo', $this->xlsxInfo((string) $response->getContent())['Filtros']);
        $this->assertFalse(AuditLog::where('user_id', $user->id)->exists());
    }
}
