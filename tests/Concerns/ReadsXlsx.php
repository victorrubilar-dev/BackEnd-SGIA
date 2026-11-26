<?php

namespace Tests\Concerns;

use Illuminate\Testing\TestResponse;
use Tests\Unit\XlsxWriterTest;

/**
 * Lee un .xlsx de `XlsxWriter` (zip sin comprimir) como hojas → filas → celdas (docs/reportes-excel.md).
 * Las celdas de texto vienen como string y las numéricas como float/int; las vacías no aparecen.
 * La fila de totales al pie (la que tiene fórmulas `SUBTOTAL`) no es una fila de datos: la da `xlsxTotals()`.
 */
trait ReadsXlsx
{
    /**
     * @return array<string, list<array<string, string|int|float>>> Nombre de hoja → filas (columna «A», «B»… → valor).
     */
    protected function readXlsx(string|TestResponse $xlsx): array
    {
        return $this->readXlsxSheets($xlsx)[0];
    }

    /**
     * Fila de totales de una hoja por cabecera (`null` si no tiene).
     *
     * @return array<string, string|int|float>|null
     */
    protected function xlsxTotals(string|TestResponse $xlsx, string $sheet): ?array
    {
        [$sheets, $totals] = $this->readXlsxSheets($xlsx);
        $this->assertArrayHasKey($sheet, $sheets, "la hoja «{$sheet}» existe");
        if (! isset($totals[$sheet])) {
            return null;
        }
        $headers = $sheets[$sheet][0];

        return collect($headers)->mapWithKeys(fn ($h, $col) => [$h => $totals[$sheet][$col] ?? null])->filter(fn ($v) => $v !== null)->all();
    }

    /**
     * @return array{0: array<string, list<array<string, string|int|float>>>, 1: array<string, array<string, string|int|float>>}
     */
    private function readXlsxSheets(string|TestResponse $xlsx): array
    {
        $content = $xlsx instanceof TestResponse ? (string) $xlsx->getContent() : $xlsx;
        $files = XlsxWriterTest::unzip($content);

        $workbook = simplexml_load_string($files['xl/workbook.xml']);
        $sheets = [];
        $totals = [];
        $n = 1;
        foreach ($workbook->sheets->sheet as $sheet) {
            $xml = simplexml_load_string($files['xl/worksheets/sheet'.$n.'.xml']);
            $rows = [];
            foreach ($xml->sheetData->row as $row) {
                $isTotals = false;
                $cells = [];
                foreach ($row->c as $c) {
                    $column = preg_replace('/\d+/', '', (string) $c['r']);
                    $cells[$column] = (string) $c['t'] === 'inlineStr'
                        ? (string) $c->is->t
                        : ((string) $c->v === (string) (int) $c->v ? (int) $c->v : (float) $c->v);
                    $isTotals = $isTotals || isset($c->f);
                }
                if ($isTotals) {
                    $totals[(string) $sheet['name']] = $cells;

                    continue;
                }
                $rows[] = $cells;
            }
            $sheets[(string) $sheet['name']] = $rows;
            $n++;
        }

        return [$sheets, $totals];
    }

    /**
     * Filas de datos de una hoja como arreglos asociativos por cabecera.
     *
     * @return list<array<string, string|int|float>>
     */
    protected function xlsxRecords(string|TestResponse $xlsx, string $sheet): array
    {
        $rows = $this->readXlsx($xlsx)[$sheet] ?? null;
        $this->assertNotNull($rows, "la hoja «{$sheet}» existe");
        $headers = array_shift($rows);

        return array_map(fn (array $row) => collect($headers)->mapWithKeys(fn ($h, $col) => [$h => $row[$col] ?? null])->filter(fn ($v) => $v !== null)->all(), $rows);
    }

    /**
     * Filas de la hoja «Resumen» unidas con «|» (`Agrupado por|Valor|Cantidad[|Monto]`), para buscarlas con `assertContains`.
     *
     * @return list<string>
     */
    protected function xlsxSummary(string|TestResponse $xlsx): array
    {
        $rows = $this->readXlsx($xlsx)['Resumen'] ?? null;
        $this->assertNotNull($rows, 'la hoja «Resumen» existe');

        return array_map(fn (array $row) => implode('|', $row), array_slice($rows, 1));
    }

    /**
     * Hoja «Info» como etiqueta → valor.
     *
     * @return array<string, string|int|float>
     */
    protected function xlsxInfo(string|TestResponse $xlsx): array
    {
        return collect($this->readXlsx($xlsx)['Info'] ?? [])->slice(1)->mapWithKeys(fn ($row) => [$row['A'] => $row['B'] ?? ''])->all();
    }
}
