<?php

namespace App\Services;

use App\Models\AnnualBudget;
use App\Models\Cajon;
use App\Models\EquipmentUnit;
use App\Models\InvoiceIntake;
use App\Models\InvoiceIntakeItem;
use App\Models\Product;
use App\Models\Purchase;
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
    public function createDraft(UploadedFile $file, ?AnnualBudget $budget, int $userId, ?Purchase $purchase = null): InvoiceIntake
    {
        if ($purchase) {
            // Sin presupuesto explícito se usa el de la compra.
            $budget ??= $purchase->annual_budget_id ? AnnualBudget::find($purchase->annual_budget_id) : null;
            $this->assertPurchaseBillable($purchase, $budget);
        }

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
            'annual_budget_id' => $budget?->id,
            'purchase_id' => $purchase?->id,
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

            $budget = $locked->annual_budget_id
                ? AnnualBudget::whereKey($locked->annual_budget_id)->lockForUpdate()->firstOrFail()
                : null;
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

            $purchase = null;

            if ($locked->purchase_id && $budget) {
                // La factura cierra la reserva de su compra: se libera y se consume el monto real.
                $purchase = Purchase::whereKey($locked->purchase_id)->lockForUpdate()->firstOrFail();
                $this->assertPurchaseBillable($purchase, $budget);
                $this->budgets->releaseReservation($budget, $purchase, $userId, $locked);
            }

            if ($budget) {
                $this->budgets->consume($budget, $totalCents / 100, $locked, $userId, $purchase);
            }

            foreach ($rows as [$product, $item, $subtotalCents]) {
                $intakeItem = $locked->items()->create([
                    'product_id' => $product->id,
                    'detected_name' => $item['detected_name'] ?? null,
                    'quantity' => $item['quantity'],
                    'unit_price' => $this->budgets->cents($item['unit_price']) / 100,
                    'subtotal' => $subtotalCents / 100,
                ]);

                $this->receiveStock($product, $item, $userId, $intakeItem);
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
     * Anula un ingreso. Un `borrador` solo cambia de estado; uno `confirmado` se revierte entero
     * (todo o nada): resta el stock de insumos, da de baja las unidades creadas y repone el
     * monto al presupuesto. Si la factura estaba ligada a una compra, la compra no cambia.
     *
     * @throws ValidationException
     */
    public function cancel(InvoiceIntake $intake, int $userId): InvoiceIntake
    {
        return DB::transaction(function () use ($intake, $userId) {
            $locked = InvoiceIntake::whereKey($intake->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === InvoiceIntake::STATUS_CANCELLED) {
                throw ValidationException::withMessages(['status' => 'El ingreso ya está anulado.']);
            }

            if ($locked->status === InvoiceIntake::STATUS_CONFIRMED) {
                $this->reverseConfirmed($locked, $userId);
            }

            $locked->update(['status' => InvoiceIntake::STATUS_CANCELLED]);

            return $locked;
        });
    }

    private function reverseConfirmed(InvoiceIntake $intake, int $userId): void
    {
        $budget = $intake->annual_budget_id
            ? AnnualBudget::whereKey($intake->annual_budget_id)->lockForUpdate()->firstOrFail()
            : null;
        $items = $intake->items()->with(['product', 'units'])->get();

        $insumoQuantities = [];
        $unitsToWriteOff = collect();

        // 1) Validar todo antes de tocar nada.
        foreach ($items as $item) {
            $product = $item->product;

            if ($product->hasUnits()) {
                if ($item->units->count() !== $item->quantity) {
                    throw ValidationException::withMessages([
                        'items' => "No se puede anular: las unidades de {$product->name} no son trazables a esta factura (ingreso anterior a la trazabilidad).",
                    ]);
                }

                foreach ($item->units as $unit) {
                    if ($unit->status !== EquipmentUnit::STATUS_AVAILABLE || $unit->loanItems()->exists()) {
                        throw ValidationException::withMessages([
                            'items' => "No se puede anular: la unidad {$unit->code} de {$product->name} no está disponible o ya tiene préstamos.",
                        ]);
                    }
                    $unitsToWriteOff->push($unit);
                }

                continue;
            }

            $insumoQuantities[$product->id] = ($insumoQuantities[$product->id] ?? 0) + $item->quantity;
        }

        $lockedProducts = Product::whereIn('id', array_keys($insumoQuantities))->lockForUpdate()->get()->keyBy('id');

        foreach ($insumoQuantities as $productId => $quantity) {
            $product = $lockedProducts[$productId];

            if ($product->quantity < $quantity) {
                throw ValidationException::withMessages([
                    'items' => "No se puede anular: el stock de {$product->name} ({$product->quantity}) es menor que lo ingresado por la factura ({$quantity}).",
                ]);
            }
        }

        // 2) Aplicar la reversa.
        foreach ($insumoQuantities as $productId => $quantity) {
            $product = $lockedProducts[$productId];
            $product->forceFill(['quantity' => $product->quantity - $quantity, 'updated_by' => $userId])->save();
            $this->stockAlerts->checkStock($product);
        }

        foreach ($unitsToWriteOff as $unit) {
            $this->units->changeStatus($unit, EquipmentUnit::STATUS_WRITTEN_OFF, $userId);
        }

        if ($budget) {
            $this->budgets->restore($budget, $intake->total_deducted ?? 0, $intake, $userId);
        }
    }

    /**
     * La compra debe ser del mismo presupuesto, no estar cancelada y conservar su reserva.
     *
     * @throws ValidationException
     */
    private function assertPurchaseBillable(Purchase $purchase, ?AnnualBudget $budget): void
    {
        if ($purchase->annual_budget_id !== $budget?->id) {
            throw ValidationException::withMessages(['purchase_id' => 'La compra pertenece a otro presupuesto.']);
        }

        if ($purchase->status === Purchase::STATUS_CANCELLED || $this->budgets->cents($purchase->reserved_amount) === 0) {
            throw ValidationException::withMessages(['purchase_id' => 'La compra está cancelada o ya fue facturada.']);
        }
    }

    /**
     * @param  array{quantity: int, cajon_id?: int|null}  $item
     */
    private function receiveStock(Product $product, array $item, int $userId, InvoiceIntakeItem $intakeItem): void
    {
        if ($product->hasUnits()) {
            $this->units->createUnits($product, $item['quantity'], [
                'cajon_id' => $item['cajon_id'],
                'invoice_intake_item_id' => $intakeItem->id,
            ], $userId);

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
