<?php

namespace Tests\Unit;

use App\Support\XlsxWriter;
use PHPUnit\Framework\TestCase;

class XlsxWriterTest extends TestCase
{
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
}
