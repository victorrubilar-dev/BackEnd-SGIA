<?php

namespace Tests\Unit;

use App\Support\XlsxSheet;
use App\Support\XlsxWriter;
use PHPUnit\Framework\TestCase;

class XlsxWriterTest extends TestCase
{
    private string $timezone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->timezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
        parent::tearDown();
    }

    /**
     * Lee las entradas de un zip «stored» (sin la extensión zip) y comprueba su CRC.
     *
     * @return array<string, string>
     */
    public static function unzip(string $zip): array
    {
        $files = [];
        $offset = 0;
        while (substr($zip, $offset, 4) === pack('V', 0x04034B50)) {
            $h = unpack('vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vnameLength/vextraLength', substr($zip, $offset + 4, 26));
            $name = substr($zip, $offset + 30, $h['nameLength']);
            $data = substr($zip, $offset + 30 + $h['nameLength'] + $h['extraLength'], $h['compressed']);
            self::assertSame(0, $h['method'], "{$name} sin comprimir");
            self::assertSame($h['crc'], crc32($data), "CRC de {$name}");
            $files[$name] = $data;
            $offset += 30 + $h['nameLength'] + $h['extraLength'] + $h['compressed'];
        }
        self::assertSame(pack('V', 0x02014B50), substr($zip, $offset, 4), 'directorio central');
        self::assertSame(pack('V', 0x06054B50), substr($zip, -22, 4), 'fin del directorio central');

        return $files;
    }

    public function test_builds_a_valid_single_sheet_workbook(): void
    {
        $xlsx = XlsxWriter::build('Préstamos', ['Código', 'Cantidad', 'Nota'], [
            ['PR-1', 3, 'A & B <c> "ñandú"'],
            ['PR-2', 2.5, null],
        ]);

        $files = self::unzip($xlsx);
        $this->assertSame(['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml'], array_keys($files));

        foreach ($files as $name => $xml) {
            $doc = new \DOMDocument;
            $this->assertTrue($doc->loadXML($xml), "{$name} es XML válido");
        }

        $sheet = simplexml_load_string($files['xl/worksheets/sheet1.xml']);
        $sheet->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $this->assertSame('Código', (string) $sheet->xpath('//s:c[@r="A1"]/s:is/s:t')[0]);
        $this->assertSame('1', (string) $sheet->xpath('//s:c[@r="A1"]/@s')[0], 'cabecera en negrita');
        $this->assertSame('3', (string) $sheet->xpath('//s:c[@r="B2"]/s:v')[0], 'número como número');
        $this->assertSame('A & B <c> "ñandú"', (string) $sheet->xpath('//s:c[@r="C2"]/s:is/s:t')[0]);
        $this->assertCount(0, $sheet->xpath('//s:c[@r="C3"]'), 'celda vacía omitida');
        $this->assertStringContainsString('name="Préstamos"', $files['xl/workbook.xml']);
    }

    public function test_column_letters_and_invalid_characters(): void
    {
        $this->assertSame(['A', 'Z', 'AA', 'AZ', 'BA'], array_map(XlsxWriter::column(...), [0, 25, 26, 51, 52]));

        $files = self::unzip(XlsxWriter::build('Hoja: [1]/?', ['Texto'], [["con\x01control\x0Bvertical"]]));
        $this->assertStringContainsString('<t xml:space="preserve">concontrolvertical</t>', $files['xl/worksheets/sheet1.xml']);
        $this->assertStringContainsString('name="Hoja   1"', $files['xl/workbook.xml']);
    }

    public function test_workbook_with_several_sheets_typed_cells_and_unique_names(): void
    {
        date_default_timezone_set('America/Santiago');
        $xlsx = XlsxWriter::workbook([
            new XlsxSheet('Datos', ['Fecha', 'Fecha y hora', 'Monto', 'Cantidad', 'RUT'], [
                [new \DateTimeImmutable('2026-03-10 00:00:00'), '2026-03-10 14:30:00', 1990000, '7', '12345678'],
            ], [0 => XlsxWriter::DATE, 1 => XlsxWriter::DATETIME, 2 => XlsxWriter::MONEY, 3 => XlsxWriter::INTEGER]),
            new XlsxSheet('datos', ['A'], [['x']]),
            new XlsxSheet('Info', ['Dato', 'Valor'], [['Filas', 1]], filter: false),
        ]);

        $files = self::unzip($xlsx);
        foreach ($files as $name => $xml) {
            $doc = new \DOMDocument;
            $this->assertTrue($doc->loadXML($xml), "{$name} es XML válido");
        }
        $this->assertArrayHasKey('xl/worksheets/sheet3.xml', $files);
        $this->assertStringContainsString('name="datos (2)"', $files['xl/workbook.xml'], 'nombres de hoja únicos');
        $this->assertSame(2, substr_count($files['xl/workbook.xml'], '_xlnm._FilterDatabase'), 'la hoja Info no lleva autofiltro');
        $this->assertStringContainsString('Target="styles.xml"', $files['xl/_rels/workbook.xml.rels']);
        $this->assertStringContainsString('Id="rId4"', $files['xl/_rels/workbook.xml.rels']);

        $sheet = $files['xl/worksheets/sheet1.xml'];
        // 2026-03-10 = 46091 días desde 1899-12-30; 14:30 = 0,604167.
        $this->assertStringContainsString('<c r="A2" s="2"><v>46091</v></c>', $sheet, 'fecha real de Excel');
        $this->assertStringContainsString('<c r="B2" s="3"><v>46091.604167</v></c>', $sheet, 'fecha y hora desde texto');
        $this->assertStringContainsString('<c r="C2" s="4"><v>1990000</v></c>', $sheet, 'monto en pesos');
        $this->assertStringContainsString('<c r="D2" s="5"><v>7</v></c>', $sheet, 'entero desde texto numérico');
        $this->assertStringContainsString('<c r="E2" t="inlineStr"><is><t xml:space="preserve">12345678</t></is></c>', $sheet, 'texto numérico sin tipo sigue como texto');
    }

    public function test_serial_uses_local_time(): void
    {
        date_default_timezone_set('America/Santiago');
        // 2026-03-10 03:00 UTC es 2026-03-10 00:00 en Chile (UTC-3 en verano).
        $this->assertSame(46091.0, XlsxWriter::serial(new \DateTimeImmutable('2026-03-10 03:00:00', new \DateTimeZone('UTC'))));
    }
}
