<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Libro de Excel (.xlsx) sin dependencias ni la extensión `zip` (docs/exportaciones-y-campana.md, docs/reportes-excel.md).
 *
 * El .xlsx es un zip con XML de SpreadsheetML. Aquí el zip se arma a mano con las entradas sin comprimir
 * («stored»): es válido para Excel, LibreOffice y Google Sheets y basta para reportes de hasta miles de filas.
 * Cabecera en negrita con fondo gris, fila congelada y autofiltro; cada columna puede declarar su tipo
 * (fecha, fecha y hora, monto en pesos, entero) y, si no, los números van como número y el resto como texto.
 */
final class XlsxWriter
{
    public const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /** Tipos de columna (`XlsxSheet::$types`). */
    public const TEXT = 'text';

    public const DATE = 'date';

    public const DATETIME = 'datetime';

    public const MONEY = 'money';

    public const INTEGER = 'integer';

    /** Índices de `cellXfs` en `styles.xml`. */
    private const STYLE_HEADER = 1;

    private const STYLE_DATE = 2;

    private const STYLE_DATETIME = 3;

    private const STYLE_MONEY = 4;

    private const STYLE_INTEGER = 5;

    /**
     * Libro de una hoja (se mantiene para el historial de préstamos).
     *
     * @param  list<string>  $headers
     * @param  iterable<array<int, string|int|float|bool|DateTimeInterface|null>>  $rows
     * @param  array<int, float|int>  $widths  Ancho de columna (en caracteres) por índice desde 0.
     */
    public static function build(string $sheetName, array $headers, iterable $rows, array $widths = []): string
    {
        return self::workbook([new XlsxSheet($sheetName, $headers, $rows, widths: $widths)]);
    }

    /**
     * Libro con varias hojas, en el orden dado.
     *
     * @param  list<XlsxSheet>  $sheets
     */
    public static function workbook(array $sheets): string
    {
        if ($sheets === []) {
            $sheets = [new XlsxSheet('Hoja1', [], [])];
        }

        $names = [];
        $sheetEntries = '';
        $definedNames = '';
        $overrides = '';
        $rels = '';
        $files = [];

        foreach ($sheets as $i => $sheet) {
            $n = $i + 1;
            $name = self::uniqueName(self::sheetName($sheet->name), $names);
            $names[] = $name;
            [$xml, $range] = self::sheetXml($sheet);

            $sheetEntries .= '<sheet name="'.self::escape($name).'" sheetId="'.$n.'" r:id="rId'.$n.'"/>';
            if ($sheet->filter && $range !== null) {
                $definedNames .= '<definedName name="_xlnm._FilterDatabase" localSheetId="'.$i.'" hidden="1">\''
                    .self::escape(str_replace("'", "''", $name)).'\'!'.$range.'</definedName>';
            }
            $overrides .= '<Override PartName="/xl/worksheets/sheet'.$n.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $rels .= '<Relationship Id="rId'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
            $files['xl/worksheets/sheet'.$n.'.xml'] = $xml;
        }
        $stylesId = 'rId'.(count($sheets) + 1);

        return self::zip([
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                .$overrides
                .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                .'</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                .'</Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                .'<sheets>'.$sheetEntries.'</sheets>'
                .($definedNames !== '' ? '<definedNames>'.$definedNames.'</definedNames>' : '')
                .'</workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .$rels
                .'<Relationship Id="'.$stylesId.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                .'</Relationships>',
            'xl/styles.xml' => self::styles(),
            ...$files,
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

    /**
     * Número de serie de Excel (días desde 1899-12-30) de la fecha y hora local (zona de la app: America/Santiago).
     */
    public static function serial(DateTimeInterface $value): float
    {
        $local = DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone(date_default_timezone_get()));
        $wall = new DateTimeImmutable($local->format('Y-m-d H:i:s'), new DateTimeZone('UTC'));

        return $wall->getTimestamp() / 86400 + 25569;
    }

    /**
     * XML de una hoja y el rango de datos (`$A$1:$C$10`), o `null` si no tiene columnas.
     *
     * @return array{0: string, 1: string|null}
     */
    private static function sheetXml(XlsxSheet $sheet): array
    {
        $sheetRows = $sheet->headers !== [] ? [self::row(1, $sheet->headers, [], true)] : [];
        $rowNumber = $sheet->headers !== [] ? 1 : 0;
        $maxColumns = count($sheet->headers);
        foreach ($sheet->rows as $row) {
            $values = array_values($row);
            $maxColumns = max($maxColumns, count($values));
            $sheetRows[] = self::row(++$rowNumber, $values, $sheet->types, false);
        }

        $lastColumn = self::column(max($maxColumns, 1) - 1);
        $lastRow = max($rowNumber, 1);
        $cols = '';
        foreach ($sheet->headers as $i => $header) {
            $width = $sheet->widths[$i] ?? self::defaultWidth($header, $sheet->types[$i] ?? null);
            $cols .= sprintf('<col min="%1$d" max="%1$d" width="%2$s" customWidth="1"/>', $i + 1, (string) $width);
        }
        $frozen = $sheet->headers !== [] && $sheet->freezeHeader;

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<dimension ref="A1:'.$lastColumn.$lastRow.'"/>'
            .'<sheetViews><sheetView workbookViewId="0">'
            .($frozen ? '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>' : '')
            .'</sheetView></sheetViews>'
            .'<sheetFormatPr defaultRowHeight="15"/>'
            .($cols !== '' ? '<cols>'.$cols.'</cols>' : '')
            .'<sheetData>'.implode('', $sheetRows).'</sheetData>'
            .($sheet->filter && $sheet->headers !== [] ? '<autoFilter ref="A1:'.$lastColumn.$lastRow.'"/>' : '')
            .'</worksheet>';

        return [$xml, $sheet->headers !== [] ? '$A$1:$'.$lastColumn.'$'.$lastRow : null];
    }

    private static function defaultWidth(string $header, ?string $type): int
    {
        $min = match ($type) {
            self::DATE => 12,
            self::DATETIME => 17,
            self::MONEY => 14,
            default => 12,
        };

        return min(max(mb_strlen($header) + 4, $min), 60);
    }

    /**
     * @param  array<int, mixed>  $values
     * @param  array<int, string>  $types
     */
    private static function row(int $number, array $values, array $types, bool $header): string
    {
        $cells = '';
        foreach ($values as $i => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $ref = self::column($i).$number;
            if ($header) {
                $cells .= self::text($ref, (string) $value, self::STYLE_HEADER);

                continue;
            }

            $type = $types[$i] ?? null;
            if (($type === self::DATE || $type === self::DATETIME) && ! $value instanceof DateTimeInterface && is_string($value)) {
                $value = self::parseDate($value) ?? $value;
            }

            if ($value instanceof DateTimeInterface) {
                $serial = self::serial($value);
                if ($type === self::DATE) {
                    $cells .= self::number($ref, floor($serial), self::STYLE_DATE);
                } else {
                    $cells .= self::number($ref, round($serial, 6), self::STYLE_DATETIME);
                }

                continue;
            }

            if ((is_int($value) || is_float($value) || ($type === self::MONEY || $type === self::INTEGER) && is_numeric($value)) && is_finite((float) $value)) {
                $style = match ($type) {
                    self::MONEY => self::STYLE_MONEY,
                    self::INTEGER => self::STYLE_INTEGER,
                    default => 0,
                };
                $cells .= self::number($ref, $value + 0, $style);

                continue;
            }

            $cells .= self::text($ref, is_bool($value) ? ($value ? 'sí' : 'no') : (string) $value, 0);
        }

        return '<row r="'.$number.'">'.$cells.'</row>';
    }

    private static function parseDate(string $value): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value, new DateTimeZone(date_default_timezone_get()));
        } catch (\Exception) {
            return null;
        }
    }

    private static function number(string $ref, int|float $value, int $style): string
    {
        $v = is_float($value) ? rtrim(rtrim(sprintf('%.6F', $value), '0'), '.') : (string) $value;

        return '<c r="'.$ref.'"'.($style > 0 ? ' s="'.$style.'"' : '').'><v>'.$v.'</v></c>';
    }

    private static function text(string $ref, string $text, int $style): string
    {
        return '<c r="'.$ref.'" t="inlineStr"'.($style > 0 ? ' s="'.$style.'"' : '').'><is><t xml:space="preserve">'.self::escape($text).'</t></is></c>';
    }

    /** Fuentes, relleno gris de la cabecera y formatos de fecha, fecha y hora, pesos y entero. */
    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="3">'
            .'<numFmt numFmtId="164" formatCode="dd\-mm\-yyyy"/>'
            .'<numFmt numFmtId="165" formatCode="dd\-mm\-yyyy\ hh:mm"/>'
            .'<numFmt numFmtId="166" formatCode="&quot;$&quot;\ #,##0"/>'
            .'</numFmts>'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFE4E4E7"/><bgColor indexed="64"/></patternFill></fill></fills>'
            .'<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
            .'<border><left/><right/><top/><bottom style="thin"><color rgb="FFA1A1AA"/></bottom><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="6">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }

    /** Excel limita el nombre de la hoja a 31 caracteres y prohíbe `[]:*?/\`. */
    private static function sheetName(string $name): string
    {
        $clean = trim((string) preg_replace('/[\[\]:*?\/\\\\]/', ' ', $name));

        return mb_substr($clean !== '' ? $clean : 'Hoja1', 0, 31);
    }

    /**
     * Excel no admite dos hojas con el mismo nombre (sin distinguir mayúsculas).
     *
     * @param  list<string>  $taken
     */
    private static function uniqueName(string $name, array $taken): string
    {
        $lower = array_map(mb_strtolower(...), $taken);
        $candidate = $name;
        for ($n = 2; in_array(mb_strtolower($candidate), $lower, true); $n++) {
            $suffix = ' ('.$n.')';
            $candidate = mb_substr($name, 0, 31 - mb_strlen($suffix)).$suffix;
        }

        return $candidate;
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
