<?php

namespace App\Console\Commands;

use App\Services\InvoiceOcrService;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Mide el lector local de facturas (sin Textract) contra los valores reales de
 * pruebas-facturas/esperado.json. Un ítem cuenta si cantidad y precio unitario coinciden.
 */
class OcrBenchmark extends Command
{
    protected $signature = 'sgia:ocr-bench {dir=pruebas-facturas}';

    protected $description = 'Mide el OCR local contra facturas con resultado conocido.';

    public function handle(InvoiceOcrService $ocr): int
    {
        config(['services.ocr.driver' => 'local']);
        $dir = base_path($this->argument('dir'));
        $expected = json_decode((string) file_get_contents("$dir/esperado.json"), true, flags: JSON_THROW_ON_ERROR);
        $rows = [];
        $totals = ['items' => 0, 'hits' => 0, 'extra' => 0, 'folio' => 0, 'supplier' => 0];

        foreach ($expected as $file => $want) {
            $start = microtime(true);
            $got = $ocr->extractProductsFromInvoice(new UploadedFile("$dir/$file", $file, test: true));
            $seconds = microtime(true) - $start;

            $read = array_map(fn ($i) => [$i['quantity'], (int) round($i['unit_price'])], $got['items']);
            $hits = count(array_filter($want['items'], fn ($w) => in_array($w, $read, true)));
            $folio = $got['invoice_number'] === $want['invoice_number'];
            $supplier = str_contains(Str::ascii(Str::lower((string) $got['supplier_name'])), $want['supplier']);

            $totals['items'] += count($want['items']);
            $totals['hits'] += $hits;
            $totals['extra'] += count($read) - $hits;
            $totals['folio'] += (int) $folio;
            $totals['supplier'] += (int) $supplier;

            $rows[] = [
                $file,
                $hits.'/'.count($want['items']).(count($read) > $hits ? ' (+'.(count($read) - $hits).' falsos)' : ''),
                ($folio ? '✓ ' : '✗ ').($got['invoice_number'] ?? '-'),
                ($supplier ? '✓ ' : '✗ ').Str::limit((string) $got['supplier_name'], 25),
                number_format($seconds, 1).' s',
                implode(' | ', array_map(fn ($i) => Str::limit($i['name'], 22).' '.$i['quantity'].'×'.$i['unit_price'], $got['items'])),
            ];
        }

        $this->table(['Factura', 'Ítems', 'Folio', 'Proveedor', 'Tiempo', 'Leído'], $rows);
        $this->line("Total: ítems {$totals['hits']}/{$totals['items']} (falsos {$totals['extra']}), folio {$totals['folio']}/".count($expected).", proveedor {$totals['supplier']}/".count($expected));

        return self::SUCCESS;
    }
}
