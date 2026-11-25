<?php

namespace Tests\Feature;

use App\Models\EquipmentUnit;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Product;
use App\Models\Storeroom;
use App\Models\User;
use App\Support\XlsxWriter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ExtractsPdfText;
use Tests\Concerns\ReadsXlsx;
use Tests\TestCase;
use Tests\Unit\XlsxWriterTest;

/**
 * Exportación del historial de préstamos (docs/exportaciones-y-campana.md).
 */
class LoanExportTest extends TestCase
{
    use DatabaseTransactions;
    use ExtractsPdfText;
    use ReadsXlsx;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_DIRECTOR, 'is_active' => true]));
    }

    public function test_excel_is_a_real_xlsx_with_one_row_per_loan(): void
    {
        $storeroom = Storeroom::factory()->create();
        $loan = Loan::factory()->create(['storeroom_id' => $storeroom->id, 'loan_date' => '2026-03-10', 'subject' => 'Redes & <Sistemas>']);

        foreach (['excel', 'xlsx'] as $format) {
            $response = $this->get("/api/loans/export?format={$format}&storeroom_id={$storeroom->id}")->assertOk();
            $this->assertSame(XlsxWriter::MIME, $response->headers->get('Content-Type'));
            $this->assertMatchesRegularExpression('/filename="prestamos-\d{8}-\d{6}\.xlsx"/', (string) $response->headers->get('Content-Disposition'));

            $sheet = XlsxWriterTest::unzip($response->getContent())['xl/worksheets/sheet1.xml'];
            $this->assertStringContainsString('<t xml:space="preserve">Código</t>', $sheet);
            $this->assertStringContainsString('<t xml:space="preserve">'.$loan->code.'</t>', $sheet);
            $this->assertStringContainsString('Redes &amp; &lt;Sistemas&gt;', $sheet);
            $this->assertStringContainsString('<dimension ref="A1:M2"/>', $sheet, 'cabecera + 1 préstamo (12 columnas + Año)');
        }
    }

    public function test_csv_stays_csv_and_dates_filter_with_from_and_to(): void
    {
        $storeroom = Storeroom::factory()->create();
        $march = Loan::factory()->create(['storeroom_id' => $storeroom->id, 'loan_date' => '2026-03-10']);
        $may = Loan::factory()->create(['storeroom_id' => $storeroom->id, 'loan_date' => '2026-05-20']);

        $response = $this->get("/api/loans/export?storeroom_id={$storeroom->id}&from=2026-03-01&to=2026-03-31")->assertOk();
        $this->assertStringStartsWith('text/csv', (string) $response->headers->get('Content-Type'));
        $csv = $response->streamedContent();
        $this->assertStringContainsString($march->code, $csv);
        $this->assertStringNotContainsString($may->code, $csv);

        $this->get("/api/loans/export?format=pdf&storeroom_id={$storeroom->id}")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->getJson('/api/loans/export?format=docx')->assertStatus(422)->assertJsonValidationErrors(['format']);
    }

    public function test_pdf_uses_the_web_style_landscape_readable_states_and_page_numbers(): void
    {
        $storeroom = Storeroom::factory()->create();
        $loan = Loan::factory()->create(['storeroom_id' => $storeroom->id, 'status' => 'procesado', 'type' => 'presencial', 'loan_date' => '2026-03-10']);

        $pdf = (string) $this->get("/api/loans/export?format=pdf&storeroom_id={$storeroom->id}&from=2026-03-01&to=2026-03-31")->assertOk()->getContent();
        $text = $this->pdfText($pdf);

        $this->assertStringContainsString('Historial de préstamos', $text);
        $this->assertStringContainsString('Del 01-03-2026 al 31-03-2026', $text);
        $this->assertStringContainsString($loan->code, $text);
        $this->assertStringContainsString('Procesado', $text);
        $this->assertStringContainsString('Presencial', $text);
        $this->assertStringNotContainsString('procesado', $text, 'sin estados crudos');
        $this->assertStringContainsString('Página 1 de 1', $text);
        $this->assertStringContainsString('Sistema de Gestión de Inventario y Activos', $text);
        // A4 horizontal y Geist embebido.
        $this->assertMatchesRegularExpression('/\/MediaBox \[0\.0+ 0\.0+ 841\.89\d* 595\.28\d*\]/', $pdf);
        $this->assertStringContainsString('Geist-Regular', $pdf);
    }

    public function test_excel_has_detail_and_info_sheets_with_real_dates_and_status_filter(): void
    {
        $storeroom = Storeroom::factory()->create();
        $loan = Loan::factory()->inProgress()->create(['storeroom_id' => $storeroom->id, 'loan_date' => '2026-03-10']);
        Loan::factory()->create(['storeroom_id' => $storeroom->id, 'loan_date' => '2026-03-11']);
        $product = Product::factory()->create(['type' => Product::TYPE_EQUIPMENT]);
        $units = EquipmentUnit::factory()->count(2)->create(['product_id' => $product->id]);
        $item = LoanItem::create(['loan_id' => $loan->id, 'product_id' => $product->id, 'name' => 'Notebook', 'quantity' => 2]);
        $item->units()->attach($units->pluck('id'));
        LoanItem::create(['loan_id' => $loan->id, 'name' => 'Cable HDMI', 'quantity' => 3]);

        $response = $this->get("/api/loans/export?format=xlsx&storeroom_id={$storeroom->id}&estado=en_proceso&devuelto=0")->assertOk();
        $this->assertSame(['Préstamos', 'Detalle', 'Info'], array_keys($this->readXlsx($response)));

        $loans = $this->xlsxRecords($response, 'Préstamos');
        $this->assertSame([$loan->code], array_column($loans, 'Código'), 'el estado filtra');
        $this->assertSame(46091, $loans[0]['Fecha'], 'fecha real de Excel (2026-03-10)');
        $this->assertSame(2026, $loans[0]['Año'], 'año de la operación junto a la fecha');
        $this->assertSame(2026, $this->xlsxRecords($response, 'Detalle')[0]['Año']);
        $this->assertSame('En proceso', $loans[0]['Estado']);

        $detail = $this->xlsxRecords($response, 'Detalle');
        $this->assertSame([...$units->pluck('code')->sort()->values()->all(), null], collect($detail)->map(fn ($r) => $r['Unidad'] ?? null)->sort(fn ($a, $b) => $a === null ? 1 : ($b === null ? -1 : strcmp($a, $b)))->values()->all(), 'una fila por unidad y una por insumo');
        $this->assertSame(3, collect($detail)->firstWhere('Ítem', 'Cable HDMI')['Cantidad']);

        $info = $this->xlsxInfo($response);
        $this->assertSame('Historial de préstamos', $info['Reporte']);
        $this->assertStringContainsString('Estado: En proceso', $info['Filtros']);
        $this->assertStringContainsString("Pañol: {$storeroom->name}", $info['Filtros']);
        $this->assertStringContainsString('Devuelto: no', $info['Filtros']);
    }
}
