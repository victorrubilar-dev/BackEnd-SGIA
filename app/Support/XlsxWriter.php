<?php

namespace App\Support;

use DateTimeInterface;

/**
 * Libro de Excel (.xlsx) de una hoja, sin dependencias ni la extensión `zip` (docs/exportaciones-y-campana.md).
 *
 * El .xlsx es un zip con XML de SpreadsheetML. Aquí el zip se arma a mano con las entradas sin comprimir
 * («stored»): es válido para Excel, LibreOffice y Google Sheets y basta para reportes de hasta miles de filas.
 * Cabecera en negrita, fila congelada y autofiltro; números como número y el resto como texto.
 */
final class XlsxWriter
{
    public const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /**
     * @param  list<string>  $headers
     * @param  iterable<array<int, string|int|float|bool|null>>  $rows
     * @param  array<int, float|int>  $widths  Ancho de columna (en caracteres) por índice desde 0.
     */
    public static function build(string $sheetName, array $headers, iterable $rows, array $widths = []): string
    {
        $sheetRows = [self::row(1, $headers, true)];
        $rowNumber = 1;
        foreach ($rows as $row) {
            $sheetRows[] = self::row(++$rowNumber, array_values($row), false);
        }

        $lastColumn = self::column(max(count($headers), 1) - 1);
        $cols = '';
        foreach ($headers as $i => $header) {
            $width = $widths[$i] ?? min(max(mb_strlen($header) + 4, 12), 60);
            $cols .= sprintf('<col min="%1$d" max="%1$d" width="%2$s" customWidth="1"/>', $i + 1, (string) $width);
        }

        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<dimension ref="A1:'.$lastColumn.$rowNumber.'"/>'
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .'<sheetFormatPr defaultRowHeight="15"/>'
            .($cols !== '' ? '<cols>'.$cols.'</cols>' : '')
            .'<sheetData>'.implode('', $sheetRows).'</sheetData>'
            .'<autoFilter ref="A1:'.$lastColumn.$rowNumber.'"/>'
            .'</worksheet>';

        return self::zip([
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                .'</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                .'</Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                .'<sheets><sheet name="'.self::escape(self::sheetName($sheetName)).'" sheetId="1" r:id="rId1"/></sheets>'
                .'<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\''.self::escape(str_replace("'", "''", self::sheetName($sheetName))).'\'!$A$1:$'.$lastColumn.'$'.$rowNumber.'</definedName></definedNames>'
                .'</workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                .'</Relationships>',
            'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
                .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
                .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
                .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
                .'<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
                .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
                .'</styleSheet>',
            'xl/worksheets/sheet1.xml' => $sheet,
        ]);
    }

    /** Letra de columna de Excel desde un índice 0 (0 → A, 25 → Z, 26 → AA). */
    public static function column(int $index): string
    {
        $letters = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letters = chr(65 + ($n - 1) % 26).$letters;
        }

        return $letters;
    }

    /** @param array<int, mixed> $values */
    private static function row(int $number, array $values, bool $header): string
    {
        $cells = '';
        foreach ($values as $i => $value) {
            $ref = self::column($i).$number;
            $style = $header ? ' s="1"' : '';
            if ($value === null || $value === '') {
                continue;
            }
            if (! $header && (is_int($value) || is_float($value)) && is_finite((float) $value)) {
                $cells .= '<c r="'.$ref.'"'.$style.'><v>'.$value.'</v></c>';

                continue;
            }
            $text = match (true) {
                is_bool($value) => $value ? 'sí' : 'no',
                $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
                default => (string) $value,
            };
            $cells .= '<c r="'.$ref.'" t="inlineStr"'.$style.'><is><t xml:space="preserve">'.self::escape($text).'</t></is></c>';
        }

        return '<row r="'.$number.'">'.$cells.'</row>';
    }

    /** Excel limita el nombre de la hoja a 31 caracteres y prohíbe `[]:*?/\`. */
    private static function sheetName(string $name): string
    {
        $clean = trim((string) preg_replace('/[\[\]:*?\/\\\\]/', ' ', $name));

        return mb_substr($clean !== '' ? $clean : 'Hoja1', 0, 31);
    }

    /** Escapa XML y quita los caracteres de control que XML 1.0 no admite (Excel no abre el archivo con ellos). */
    private static function escape(string $text): string
    {
        $text = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text);

        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * Zip con las entradas sin comprimir (método 0), con el nombre en UTF-8 (bit 11).
     *
     * @param  array<string, string>  $files
     */
    private static function zip(array $files): string
    {
        $now = getdate();
        $time = ($now['hours'] << 11) | ($now['minutes'] << 5) | intdiv($now['seconds'], 2);
        $date = (max($now['year'], 1980) - 1980) << 9 | ($now['mon'] << 5) | $now['mday'];

        $body = '';
        $central = '';
        foreach ($files as $name => $data) {
            $crc = crc32($data);
            $size = strlen($data);
            $offset = strlen($body);
            $common = pack('vvvvvVVVvv', 20, 0x0800, 0, $time, $date, $crc, $size, $size, strlen($name), 0);

            $body .= pack('V', 0x04034B50).$common.$name.$data;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, 20, 0x0800, 0, $time, $date, $crc, $size, $size, strlen($name), 0, 0, 0, 0, 0, $offset).$name;
        }

        return $body.$central.pack('VvvvvVVv', 0x06054B50, 0, 0, count($files), count($files), strlen($central), strlen($body), 0);
    }
}
