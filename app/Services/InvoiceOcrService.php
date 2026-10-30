<?php

namespace App\Services;

use App\Models\Supplier;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Lee facturas chilenas (DTE). Con OCR_DRIVER=textract usa AWS Textract
 * (AnalyzeExpense) y, si falla o no admite el archivo, cae al lector local:
 * texto embebido del PDF (pdftotext) y, si no hay, Tesseract. El resultado es
 * un borrador que el usuario confirma, no una fuente de verdad.
 */
class InvoiceOcrService
{
    public function __construct(private readonly TextractExpenseReader $textract) {}

    /**
     * @return array{
     *     invoice_number: string|null,
     *     supplier_name: string|null,
     *     supplier_id: int|null,
     *     items: array<int, array{name: string, quantity: int, unit_price: float, area: string|null}>
     * }
     *
     * @throws RuntimeException si el binario de lectura no está disponible.
     */
    public function extractProductsFromInvoice(UploadedFile $file): array
    {
        if ($this->textract->isConfigured()) {
            try {
                $result = $this->textract->read(File::get($file->getRealPath()));
                $supplierName = $result['supplier_name'];

                return $result + ['supplier_id' => $this->supplierId($supplierName)];
            } catch (RuntimeException $e) {
                // Credenciales vencidas, red caída, PDF multipágina o WEBP: se lee en local.
                report($e);
            }
        }

        return $this->parse($this->readText($file));
    }

    public function readText(UploadedFile $file): string
    {
        $path = $file->getRealPath();

        if (strtolower($file->getClientOriginalExtension()) !== 'pdf') {
            return $this->tesseract($path);
        }

        $text = $this->run(['pdftotext', '-layout', $path, '-']);
        if (mb_strlen(trim($text)) >= 40) {
            return $text;
        }

        // PDF escaneado: se rasterizan las primeras páginas y se pasan por OCR.
        $dir = sys_get_temp_dir().'/sgia-ocr-'.uniqid();
        File::makeDirectory($dir);

        try {
            $this->run(['pdftoppm', '-r', '200', '-l', '3', '-png', $path, $dir.'/p']);

            return collect(File::glob($dir.'/p*.png'))
                ->map(fn (string $png) => $this->tesseract($png))
                ->implode("\n");
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /**
     * @return array{invoice_number: string|null, supplier_name: string|null, supplier_id: int|null, items: array<int, array<string, mixed>>}
     */
    public function parse(string $text): array
    {
        $supplierName = $this->supplierName($text);

        return [
            'invoice_number' => $this->invoiceNumber($text),
            'supplier_name' => $supplierName,
            'supplier_id' => $this->supplierId($supplierName),
            'items' => $this->items($text),
        ];
    }

    private function supplierId(?string $name): ?int
    {
        return $name ? Supplier::where('name', 'ilike', $name)->value('id') : null;
    }

    private function invoiceNumber(string $text): ?string
    {
        if (preg_match('/FACTURA[^\n\d]*?N\s*[°º.o]*\s*:?\s*(\d+)/iu', $text, $m)
            || preg_match('/FOLIO\s*:?\s*(\d+)/iu', $text, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * El emisor es la primera línea con letras que no sea un RUT ni el título.
     */
    private function supplierName(string $text): ?string
    {
        foreach (preg_split('/\R/u', $text) as $line) {
            $line = trim(preg_replace('/\s{2,}.*/u', '', trim($line)));

            if ($line !== '' && preg_match('/\pL{3}/u', $line)
                && ! preg_match('/R\.?U\.?T|FACTURA|S\.?I\.?I|^N\s*[°º]/iu', $line)) {
                return $line;
            }
        }

        return null;
    }

    /**
     * Líneas "descripción  cantidad  precio  total". Se exige que
     * cantidad × precio ≈ total para descartar encabezados y texto suelto.
     *
     * @return array<int, array{name: string, quantity: int, unit_price: float, area: string|null}>
     */
    private function items(string $text): array
    {
        $items = [];

        foreach (preg_split('/\R/u', $text) as $line) {
            if (! preg_match('/^\s*(.+?)\s+(\d+)\s+\$?\s*([\d.]+)\s+\$?\s*([\d.]+)\s*$/u', $line, $m)) {
                continue;
            }

            [$quantity, $price, $total] = [(int) $m[2], (float) str_replace('.', '', $m[3]), (float) str_replace('.', '', $m[4])];

            if ($quantity > 0 && $price > 0 && abs($quantity * $price - $total) <= 1) {
                $items[] = ['name' => trim($m[1]), 'quantity' => $quantity, 'unit_price' => $price, 'area' => null];
            }
        }

        return $items;
    }

    private function tesseract(string $path): string
    {
        return $this->run(['tesseract', $path, 'stdout', '-l', config('services.tesseract.lang'), '--psm', '6']);
    }

    /**
     * @param  array<int, string>  $command
     */
    private function run(array $command): string
    {
        $result = Process::timeout(60)->run($command);

        if ($result->failed()) {
            throw new RuntimeException("Falló {$command[0]}: ".trim($result->errorOutput()));
        }

        return $result->output();
    }
}
