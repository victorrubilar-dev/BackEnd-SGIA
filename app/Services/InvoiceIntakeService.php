<?php

namespace App\Services;

use App\Models\AnnualBudget;
use App\Models\Cajon;
use App\Models\EquipmentUnit;
use App\Models\InvoiceIntake;
use App\Models\InvoiceIntakeItem;
use App\Models\Product;
use App\Models\Purchase;
use Illuminate\Database\Eloquent\Builder;
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
        private readonly PurchaseService $purchases,
    ) {}

    /**
     * Guarda el archivo en el disco privado y crea el ingreso en `borrador` con el resultado del OCR.
     * Si el OCR no está disponible el borrador queda sin ítems detectados (ingreso manual).
     */
    public function createDraft(UploadedFile $file, int $userId): InvoiceIntake
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
            'pdf_path' => $file->store('invoice-intakes', 'local'),
            'document_number' => $payload['invoice_number'],
            'raw_ocr_payload' => $payload + ['ocr_available' => $ocrAvailable],
            'status' => InvoiceIntake::STATUS_DRAFT,
        ]);
    }

    /**
     * Confirma el borrador e ingresa el stock (no toca presupuestos ni el estado de la compra), todo en una
     * transacción. Liga la factura a una orden de compra: la indicada en `$purchaseId` o, si no se indica,
     * la que coincida con lo facturado (ver `matchPurchase`).
     *
     * @param  array<int, array{product_id: int, quantity: int, unit_price: float|int|string, detected_name?: string|null, cajon_id?: int|null}>  $items
     *
     * @throws ValidationException
     */
    public function confirm(InvoiceIntake $intake, string $documentNumber, array $items, int $userId, ?int $purchaseId = null): InvoiceIntake
    {
        return DB::transaction(function () use ($intake, $documentNumber, $items, $userId, $purchaseId) {
            $locked = InvoiceIntake::whereKey($intake->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== InvoiceIntake::STATUS_DRAFT) {
                throw ValidationException::withMessages([
                    'status' => "Solo se puede confirmar un ingreso en borrador (estado actual: {$locked->status}).",
                ]);
            }

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

            $purchase = $purchaseId !== null
                ? $this->linkablePurchase($purchaseId)
                : $this->matchPurchase($documentNumber, $items);

            $locked->update([
                'document_number' => $documentNumber,
                'total_deducted' => $totalCents / 100,
                'purchase_id' => $purchase?->id ?? $locked->purchase_id,
                'status' => InvoiceIntake::STATUS_CONFIRMED,
            ]);

            if ($purchase !== null) {
                $this->settlePurchase($purchase, $locked, $documentNumber, $totalCents, $userId);
            }

            return $locked;
        });
    }

    /**
     * Con la factura ligada: libera la reserva de la orden, consume del presupuesto de la orden el
     * total facturado (queda en `annual_budget_id` para que la anulación lo reponga) y la deja `completa`.
     * Si el total supera el disponible, `consume` lanza 422 y se revierte todo el ingreso.
     */
    private function settlePurchase(Purchase $purchase, InvoiceIntake $intake, string $documentNumber, int $totalCents, int $userId): void
    {
        if ($purchase->annual_budget_id !== null) {
            $budget = $purchase->annualBudget;
            $this->budgets->releaseReservation($budget, $purchase, $userId, $intake);
            $this->budgets->consume($budget, $totalCents / 100, $intake, $userId, $purchase);
            $intake->update(['annual_budget_id' => $budget->id]);
        }

        if ($purchase->status === Purchase::STATUS_COMPLETED) {
            $purchase->update(['invoice_number' => $purchase->invoice_number ?? $documentNumber]);
        } else {
            $this->purchases->completeFromArrival($purchase, ['invoice_number' => $documentNumber]);
        }
    }

    /**
     * Compra indicada a mano: debe estar abierta o completa sin factura vigente.
     *
     * @throws ValidationException
     */
    private function linkablePurchase(int $purchaseId): Purchase
    {
        $purchase = $this->linkableQuery()->whereKey($purchaseId)->lockForUpdate()->first();

        if ($purchase === null) {
            throw ValidationException::withMessages([
                'purchase_id' => 'La orden de compra no existe, está cancelada o ya tiene una factura asociada.',
            ]);
        }

        return $purchase;
    }

    /** Órdenes que aún esperan factura: no canceladas y sin otro ingreso vigente. */
    private function linkableQuery(): Builder
    {
        return Purchase::linkable();
    }

    /**
     * Busca la orden de compra pendiente de factura (pendiente / en camino / completa por guía, sin otra factura vigente) cuyos ítems
     * coinciden con la factura: mismos productos y mismas cantidades. Si hay varias, prefiere la que
     * ya tiene este número de factura; si sigue habiendo ambigüedad no asocia nada.
     * shortcut: coincidencia exacta, una factura con ítems extra (flete) no se asocia sola; usar `purchase_id`.
     *
     * @param  array<int, array{product_id: int, quantity: int}>  $items
     */
    private function matchPurchase(string $documentNumber, array $items): ?Purchase
    {
        $invoiced = [];
        foreach ($items as $item) {
            $invoiced[$item['product_id']] = ($invoiced[$item['product_id']] ?? 0) + $item['quantity'];
        }
        ksort($invoiced);

        $candidates = $this->linkableQuery()
            ->whereHas('items', fn ($q) => $q->whereIn('product_id', array_keys($invoiced)))
            ->with('items')
            ->lockForUpdate()
            ->get()
            ->filter(function (Purchase $purchase) use ($invoiced) {
                $ordered = [];
                foreach ($purchase->items as $item) {
                    $ordered[$item->product_id] = ($ordered[$item->product_id] ?? 0) + $item->quantity;
                }
                ksort($ordered);

                return $ordered === $invoiced;
            });

        if ($candidates->count() > 1) {
            $candidates = $candidates->where('invoice_number', $documentNumber);
        }

        return $candidates->count() === 1 ? $candidates->first() : null;
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

        // Solo ingresos anteriores a este cambio descontaron presupuesto.
        if ($intake->annual_budget_id) {
            $budget = AnnualBudget::whereKey($intake->annual_budget_id)->lockForUpdate()->firstOrFail();
            $this->budgets->restore($budget, $intake->total_deducted ?? 0, $intake, $userId);
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
