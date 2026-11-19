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
    public function __construct(
        private readonly TextractExpenseReader $textract,
        private readonly CareerDetector $careers,
    ) {}

    /**
     * @return array{
     *     invoice_number: string|null,
     *     supplier_name: string|null,
     *     supplier_id: int|null,
     *     career_id: int|null,
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
                $text = $result['text'];
                unset($result['text']);

                // Textract a veces no entrega el folio: se busca en su texto y, si no, con el lector local.
                $result['invoice_number'] ??= $this->invoiceNumber($text) ?? $this->localInvoiceNumber($file);

                return $result + [
                    'supplier_id' => $this->supplierId($result['supplier_name']),
                    'career_id' => $this->careers->detect($text)?->id,
                ];
            } catch (RuntimeException $e) {
                // Credenciales vencidas, red caída, PDF multipágina o WEBP: se lee en local.
                report($e);
            }
        }

        $parsed = $this->parse($this->readText($file));
        $parsed['invoice_number'] ??= $this->localInvoiceNumber($file, retryOnly: true);

        return $parsed;
    }

    /**
     * Folio con Tesseract. La segmentación de página por defecto (psm 3) lee mejor los encabezados con
     * recuadro que psm 6; con `$retryOnly` se omite el psm 6 porque `parse` ya lo intentó.
     */
    private function localInvoiceNumber(UploadedFile $file, bool $retryOnly = false): ?string
    {
        if (strtolower($file->getClientOriginalExtension()) === 'pdf') {
            return $retryOnly ? null : $this->invoiceNumber($this->readText($file));
        }

        return ($retryOnly ? null : $this->invoiceNumber($this->tesseract($file->getRealPath())))
            ?? $this->invoiceNumber($this->tesseract($file->getRealPath(), '3'));
    }

    public function readText(UploadedFile $file): string
    {
        $path = $file->getRealPath();

        if (strtolower($file->getClientOriginalExtension()) !== 'pdf') {
            return $this->imageText($path);
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
     * @return array{invoice_number: string|null, supplier_name: string|null, supplier_id: int|null, career_id: int|null, items: array<int, array<string, mixed>>}
     */
    public function parse(string $text): array
    {
        $supplierName = $this->supplierName($text);

        return [
            'invoice_number' => $this->invoiceNumber($text),
            'supplier_name' => $supplierName,
            'supplier_id' => $this->supplierId($supplierName),
            'career_id' => $this->careers->detect($text)?->id,
            'items' => $this->items($text),
        ];
    }

    private function supplierId(?string $name): ?int
    {
        return $name ? Supplier::where('name', 'ilike', $name)->value('id') : null;
    }

    private function invoiceNumber(string $text): ?string
    {
        // Tesseract lee "N°" como "N*", "N?", "Ne" o "No"; el número suele ir bajo "FACTURA ELECTRONICA".
        if (preg_match('/FACTURA[^\n\d]*?N\s*[°º.o*?e]*\s*:?\s*(\d+)/iu', $text, $m)
            || preg_match('/FOLIO\s*:?\s*(\d+)/iu', $text, $m)
            || preg_match('/(?:^|\s)N\s*[°º*?e]\s*:?\s*(\d{3,})\b/mu', $text, $m)) {
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
     * Ítems en líneas que terminan en "precio total", con la cantidad antes o después de la
     * descripción ("PILA AAA 20 1.190 23.800", "50,00 GUANTES 4.632,00 231.600"). La cantidad
     * se calcula como total ÷ precio: Tesseract la lee mal más seguido que los montos, y exigir
     * que la división sea entera descarta encabezados y texto suelto.
     * Las lecturas de una misma imagen vienen separadas por "\f": de cada ítem (cantidad y precio)
     * se conservan tantas apariciones como en la lectura que más tenga.
     *
     * @return array<int, array{name: string, quantity: int, unit_price: float, area: string|null}>
     */
    private function items(string $text): array
    {
        $best = [];

        foreach (explode("\f", $text) as $reading) {
            $found = [];
            foreach ($this->readingItems($reading) as $item) {
                $found[$item['quantity'].'|'.$item['unit_price']][] = $item;
            }
            foreach ($found as $key => $list) {
                if (count($list) > count($best[$key] ?? [])) {
                    $best[$key] = $list;
                }
            }
        }

        return array_merge(...array_values($best));
    }

    /**
     * @return array<int, array{name: string, quantity: int, unit_price: float, area: string|null}>
     */
    private function readingItems(string $text): array
    {
        $items = [];
        $lines = preg_split('/\R/u', $text);
        $number = '\$?\s*(\d(?:[\d.,]*\d)?)';

        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*(\d+)\s+\d+\s+\$?\s*([\d.]+)\s+\$?\s*([\d.]+)\s*$/u', $line, $c) && ! empty($lines[$i + 1])) {
                // Formato "cant código precio total" con la descripción en la línea siguiente.
                $line = trim($lines[$i + 1])." $c[1] $c[2] $c[3]";
            }

            if (! preg_match("/^(.*\\pL{3}.*?)\\s+$number\\s+$number(?:\\s+\\pL)?[\\s\\W_]*$/u", $line, $m)) {
                continue;
            }

            [$price, $total] = [self::amount($m[2]), self::amount($m[3])];
            $quantity = $price > 0 ? (int) round($total / $price) : 0;

            // Con precios de un dígito la tolerancia de $1 deja pasar cualquier número ("TOTAL 5 2044").
            if ($price < 10 || $quantity < 1 || abs($quantity * $price - $total) > 1
                || preg_match('/TOTAL|NETO|I\.?V\.?A|DESCUENTO/iu', $m[1])) {
                continue;
            }

            // Se quita la cantidad (y el ruido de la columna) de cualquiera de los dos extremos.
            $name = preg_replace('/\s+\d+(?:[.,]\d+)?$/u', '', trim($m[1]));
            $name = preg_replace('/^[^\pL\d]*(?:'.$quantity.'(?:[.,]\d*)?[^\pL\d]*)?/u', '', $name);

            $items[] = ['name' => trim($name), 'quantity' => $quantity, 'unit_price' => $price, 'area' => null];
        }

        return $items;
    }

    /**
     * Montos en formato chileno ("1.250.000", "$ 250.000", "1.250,50") o con punto decimal
     * ("50.00"): un grupo de miles siempre tiene 3 dígitos, así que si el último separador va
     * seguido de 1 o 2 es decimal.
     */
    public static function amount(string $text): float
    {
        $clean = preg_replace('/[^\d.,]/', '', $text);

        if (preg_match('/^(.*)[.,](\d{1,2})$/', $clean, $m)) {
            return (float) (preg_replace('/\D/', '', $m[1]).'.'.$m[2]);
        }

        return (float) preg_replace('/\D/', '', $clean);
    }

    /**
     * Ningún ajuste sirve para todas las fotos (pruebas-facturas: la original lee bien prueba1 y la
     * ampliada en gris, prueba2), así que se lee de varias formas y `items` combina las lecturas,
     * separadas por "\f". Se mide con `php artisan sgia:ocr-bench`.
     */
    private function imageText(string $path): string
    {
        $readings = [$this->tesseract($path), $this->tesseract($path, '4')];
        // Sin la extensión GD se lee solo la imagen original.
        $image = function_exists('imagecreatefromstring') ? @imagecreatefromstring(File::get($path)) : false;

        if ($image === false) {
            return implode("\f", $readings);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'sgia-ocr');

        try {
            // Se amplía hasta 2400 px de ancho; las fotos más grandes quedan como están.
            $gray = imagescale($image, max(imagesx($image), 2400));
            imagefilter($gray, IMG_FILTER_GRAYSCALE);
            imagepng($gray, $tmp);
            $readings[] = $this->tesseract($tmp);

            imagefilter($gray, IMG_FILTER_CONTRAST, -40);
            imagepng($gray, $tmp);
            $readings[] = $this->tesseract($tmp);
        } finally {
            File::delete($tmp);
        }

        return implode("\f", $readings);
    }

    private function tesseract(string $path, string $psm = '6'): string
    {
        return $this->run(['tesseract', $path, 'stdout', '-l', config('services.tesseract.lang'), '--psm', $psm]);
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
