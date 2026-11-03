<?php

namespace App\Services;

use App\Models\AnnualBudget;
use App\Models\Cajon;
use App\Models\InvoiceIntake;
use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class InvoiceIntakeService
{
    public function __construct(
        private readonly InvoiceOcrService $ocr,
        private readonly EquipmentUnitService $units,
        private readonly StockAlertService $stockAlerts,
        private readonly AnnualBudgetService $budgets,
    ) {}

    /**
     * Guarda el archivo en el disco privado y crea el ingreso en `borrador` con el resultado del OCR.
     * Si el OCR no está disponible el borrador queda sin ítems detectados (ingreso manual).
     */
    public function createDraft(UploadedFile $file, AnnualBudget $budget, int $userId): InvoiceIntake
    {
        $payload = ['invoice_number' => null, 'supplier_name' => null, 'supplier_id' => null, 'items' => []];
        $ocrAvailable = true;

        try {
            $payload = $this->ocr->extractProductsFromInvoice($file);
        } catch (RuntimeException $e) {
            report($e);
            $ocrAvailable = false;
        }

        return InvoiceIntake::create([
            'user_id' => $userId,
            'annual_budget_id' => $budget->id,
            'pdf_path' => $file->store('invoice-intakes', 'local'),
            'document_number' => $payload['invoice_number'],
            'raw_ocr_payload' => $payload + ['ocr_available' => $ocrAvailable],
            'status' => InvoiceIntake::STATUS_DRAFT,
        ]);
    }

    /**
     * Confirma el borrador: descuenta el presupuesto e ingresa el stock, todo en una transacción.
     *
     * @param  array<int, array{product_id: int, quantity: int, unit_price: float|int|string, detected_name?: string|null, cajon_id?: int|null}>  $items
     *
     * @throws ValidationException
     */
    public function confirm(InvoiceIntake $intake, string $documentNumber, array $items, int $userId): InvoiceIntake
    {
        return DB::transaction(function () use ($intake, $documentNumber, $items, $userId) {
            $locked = InvoiceIntake::whereKey($intake->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== InvoiceIntake::STATUS_DRAFT) {
                throw ValidationException::withMessages([
                    'status' => "Solo se puede confirmar un ingreso en borrador (estado actual: {$locked->status}).",
                ]);
            }

            $budget = AnnualBudget::whereKey($locked->annual_budget_id)->lockForUpdate()->firstOrFail();
            $products = Product::whereIn('id', array_column($items, 'product_id'))->get()->keyBy('id');

            $rows = [];
            $totalCents = 0;

            foreach ($items as $index => $item) {
                $product = $products[$item['product_id']];

                if ($product->hasUnits()) {
                    if (empty($item['cajon_id']) || ! Cajon::whereKey($item['cajon_id'])->exists()) {
                        throw ValidationException::withMessages([
                            "items.$index.cajon_id" => "El producto {$product->name} se ingresa por unidades: indica el cajón donde se guardará.",
                        ]);
                    }
                }

                $subtotalCents = $this->budgets->cents($item['unit_price']) * $item['quantity'];
                $totalCents += $subtotalCents;
                $rows[] = [$product, $item, $subtotalCents];
            }

            if ($totalCents > $this->budgets->cents($budget->available_amount)) {
                throw ValidationException::withMessages([
                    'items' => 'El total de la factura ($'.number_format($totalCents / 100, 0, ',', '.').') supera el presupuesto disponible ($'.number_format((float) $budget->available_amount, 0, ',', '.').').',
                ]);
            }

            $budget->update([
                'available_amount' => ($this->budgets->cents($budget->available_amount) - $totalCents) / 100,
                'updated_by' => $userId,
            ]);

            foreach ($rows as [$product, $item, $subtotalCents]) {
                $locked->items()->create([
                    'product_id' => $product->id,
                    'detected_name' => $item['detected_name'] ?? null,
                    'quantity' => $item['quantity'],
                    'unit_price' => $this->budgets->cents($item['unit_price']) / 100,
                    'subtotal' => $subtotalCents / 100,
                ]);

                $this->receiveStock($product, $item, $userId);
            }

            $locked->update([
                'document_number' => $documentNumber,
                'total_deducted' => $totalCents / 100,
                'status' => InvoiceIntake::STATUS_CONFIRMED,
            ]);

            return $locked;
        });
    }

    /**
     * @throws ValidationException
     */
    public function cancel(InvoiceIntake $intake): InvoiceIntake
    {
        return DB::transaction(function () use ($intake) {
            $locked = InvoiceIntake::whereKey($intake->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== InvoiceIntake::STATUS_DRAFT) {
                throw ValidationException::withMessages([
                    'status' => 'Solo se puede anular un ingreso en borrador; uno confirmado se corrige con un ajuste manual de stock.',
                ]);
            }

            $locked->update(['status' => InvoiceIntake::STATUS_CANCELLED]);

            return $locked;
        });
    }

    /**
     * @param  array{quantity: int, cajon_id?: int|null}  $item
     */
    private function receiveStock(Product $product, array $item, int $userId): void
    {
        if ($product->hasUnits()) {
            $this->units->createUnits($product, $item['quantity'], ['cajon_id' => $item['cajon_id']], $userId);

            return;
        }

        $locked = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
        $locked->forceFill([
            'quantity' => $locked->quantity + $item['quantity'],
            'updated_by' => $userId,
        ])->save();

        $this->stockAlerts->checkStock($locked);
    }
}
