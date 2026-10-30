<?php

namespace App\Services;

use Aws\Exception\AwsException;
use Aws\Textract\TextractClient;
use RuntimeException;

/**
 * Lee una factura con AWS Textract AnalyzeExpense y la deja en el mismo
 * formato que el parseo local de InvoiceOcrService.
 */
class TextractExpenseReader
{
    public function isConfigured(): bool
    {
        return config('services.ocr.driver') === 'textract'
            && filled(config('services.textract.key'))
            && filled(config('services.textract.secret'));
    }

    /**
     * @return array{invoice_number: string|null, supplier_name: string|null, items: array<int, array{name: string, quantity: int, unit_price: float, area: null}>}
     *
     * @throws RuntimeException si Textract rechaza el documento o no responde.
     */
    public function read(string $bytes): array
    {
        try {
            $result = app(TextractClient::class)->analyzeExpense(['Document' => ['Bytes' => $bytes]]);
        } catch (AwsException $e) {
            throw new RuntimeException('Falló Textract: '.$e->getAwsErrorCode().' '.$e->getAwsErrorMessage(), 0, $e);
        }

        $document = $result['ExpenseDocuments'][0] ?? [];
        $summary = [];

        foreach ($document['SummaryFields'] ?? [] as $field) {
            $summary[$field['Type']['Text'] ?? ''] ??= $field['ValueDetection']['Text'] ?? null;
        }

        $number = $summary['INVOICE_RECEIPT_ID'] ?? null;

        return [
            'invoice_number' => $number !== null && preg_match('/\d+/', $number, $m) ? $m[0] : null,
            'supplier_name' => filled($summary['VENDOR_NAME'] ?? null) ? trim($summary['VENDOR_NAME']) : null,
            'items' => $this->items($document['LineItemGroups'] ?? []),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<int, array{name: string, quantity: int, unit_price: float, area: null}>
     */
    private function items(array $groups): array
    {
        $items = [];

        foreach ($groups as $group) {
            foreach ($group['LineItems'] ?? [] as $line) {
                $fields = [];
                foreach ($line['LineItemExpenseFields'] ?? [] as $field) {
                    $fields[$field['Type']['Text'] ?? ''] ??= $field['ValueDetection']['Text'] ?? null;
                }

                $name = trim((string) ($fields['ITEM'] ?? ''));
                $quantity = isset($fields['QUANTITY']) ? (int) round($this->amount($fields['QUANTITY'])) : 1;
                $price = isset($fields['UNIT_PRICE']) ? $this->amount($fields['UNIT_PRICE']) : 0.0;
                $total = isset($fields['PRICE']) ? $this->amount($fields['PRICE']) : 0.0;

                if ($price <= 0 && $total > 0 && $quantity > 0) {
                    $price = round($total / $quantity, 2);
                }

                if ($name !== '' && $quantity > 0 && $price > 0) {
                    $items[] = ['name' => $name, 'quantity' => $quantity, 'unit_price' => $price, 'area' => null];
                }
            }
        }

        return $items;
    }

    /**
     * Formato chileno: "1.250.000", "$ 250.000", "1.250,50".
     */
    private function amount(string $text): float
    {
        $clean = preg_replace('/[^\d.,]/', '', $text);

        if (str_contains($clean, ',')) {
            $clean = str_replace(',', '.', str_replace('.', '', $clean));
        } else {
            $clean = str_replace('.', '', $clean);
        }

        return (float) $clean;
    }
}
