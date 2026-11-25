<?php

namespace Tests\Concerns;

use Illuminate\Testing\TestResponse;
use Tests\Unit\XlsxWriterTest;

/**
 * Lee un .xlsx de `XlsxWriter` (zip sin comprimir) como hojas → filas → celdas (docs/reportes-excel.md).
 * Las celdas de texto vienen como string y las numéricas como float/int; las vacías no aparecen.
 */
trait ReadsXlsx
{
    /**
     * @return array<string, list<array<string, string|int|float>>> Nombre de hoja → filas (columna «A», «B»… → valor).
     */
    protected function readXlsx(string|TestResponse $xlsx): array
    {
        $content = $xlsx instanceof TestResponse ? (string) $xlsx->getContent() : $xlsx;
        $files = XlsxWriterTest::unzip($content);

        $workbook = simplexml_load_string($files['xl/workbook.xml']);
        $sheets = [];
        $n = 1;
        foreach ($workbook->sheets->sheet as $sheet) {
            $xml = simplexml_load_string($files['xl/worksheets/sheet'.$n.'.xml']);
            $rows = [];
            foreach ($xml->sheetData->row as $row) {
                $cells = [];
                foreach ($row->c as $c) {
                    $column = preg_replace('/\d+/', '', (string) $c['r']);
                    $cells[$column] = (string) $c['t'] === 'inlineStr'
                        ? (string) $c->is->t
                        : ((string) $c->v === (string) (int) $c->v ? (int) $c->v : (float) $c->v);
                }
                $rows[] = $cells;
            }
            $sheets[(string) $sheet['name']] = $rows;
            $n++;
        }

        return $sheets;
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
     * Hoja «Info» como etiqueta → valor.
     *
     * @return array<string, string|int|float>
     */
    protected function xlsxInfo(string|TestResponse $xlsx): array
    {
        return collect($this->readXlsx($xlsx)['Info'] ?? [])->slice(1)->mapWithKeys(fn ($row) => [$row['A'] => $row['B'] ?? ''])->all();
    }
}
