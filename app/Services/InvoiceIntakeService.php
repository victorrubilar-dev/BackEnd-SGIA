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
        private readonly PurchaseRequestService $purchaseRequests,
    ) {}

    /**
     * Guarda el archivo en el disco privado y crea el ingreso en `borrador` con el resultado del OCR.
     * Si el OCR no está disponible el borrador queda sin ítems detectados (ingreso manual).
     * `$requireReservation = false` (OC de una solicitud `con_oc`, ya validada): acepta la OC aunque su reserva
     * se haya liberado al anular una factura anterior, igual que cuando se liga al confirmar.
     */
    public function createDraft(UploadedFile $file, ?AnnualBudget $budget, int $userId, ?Purchase $purchase = null, bool $requireReservation = true): InvoiceIntake
    {
        if ($purchase) {
            // Sin presupuesto explícito se usa el de la compra.
            $budget ??= $purchase->annual_budget_id ? AnnualBudget::find($purchase->annual_budget_id) : null;
            $this->assertPurchaseBillable($purchase, $budget, $requireReservation);
        }

        $payload = ['invoice_number' => null, 'supplier_name' => null, 'supplier_id' => null, 'career_id' => null, 'items' => []];
        $ocrAvailable = true;

        try {
            $payload = $this->ocr->extractProductsFromInvoice($file);
        } catch (RuntimeException $e) {
            report($e);
            $ocrAvailable = false;
        }

        // Sin presupuesto explícito ni de la compra, se usa el de la carrera detectada en el documento.
        $budget ??= $this->budgetForCareer($payload['career_id'] ?? null);

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
     * Confirma el borrador: ingresa el stock y descuenta el presupuesto, todo en una
     * transacción. Liga la factura a una orden de compra: la indicada en `$purchaseId` o, si no se indica,
     * la que coincida con lo facturado (ver `matchPurchase`).
     *
     * @param  array<int, array{product_id: int, quantity: int, unit_price: float|int|string, detected_name?: string|null, cajon_id?: int|null}>  $items
     *
     * @throws ValidationException
     */
    public function confirm(InvoiceIntake $intake, string $documentNumber, array $items, int $userId, ?int $purchaseId = null, ?int $budgetId = null, bool $allowOverBudget = false, bool $canAuthorizeOver = false): InvoiceIntake
    {
        return DB::transaction(function () use ($intake, $documentNumber, $items, $userId, $purchaseId, $budgetId, $allowOverBudget, $canAuthorizeOver) {
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

            // Compra: la del borrador, la indicada a mano o la que coincide con lo facturado.
            $purchaseId ??= $locked->purchase_id;
            $purchase = match (true) {
                $purchaseId === null => $this->matchPurchase($documentNumber, $items),
                $purchaseId === $locked->purchase_id => Purchase::whereKey($purchaseId)->lockForUpdate()->firstOrFail(),
                default => $this->linkablePurchase($purchaseId),
            };

            $this->assertNotDuplicate($locked, $documentNumber, $purchase->supplier_id ?? ($locked->raw_ocr_payload['supplier_id'] ?? null));

            // Con compra manda su presupuesto; sin compra, el elegido o el del borrador (por carrera detectada).
            $budgetId ??= $locked->annual_budget_id ?? $purchase?->annual_budget_id;

            if ($purchase?->annual_budget_id !== null && $budgetId !== $purchase->annual_budget_id) {
                throw ValidationException::withMessages(['purchase_id' => 'La compra pertenece a otro presupuesto.']);
            }

            if ($purchase === null && blank($budgetId)) {
                throw ValidationException::withMessages([
                    'annual_budget_id' => 'Selecciona el presupuesto al que se descuenta la factura (no se detectó la carrera ni una orden de compra).',
                ]);
            }

            // Sin asociar, la reserva de la OC quedaría colgada y el gasto se contaría doble: se obliga a elegir la OC.
            // shortcut: no hay forma de facturar sin OC mientras el presupuesto tenga OC pendientes; agregar un flag si hace falta.
            if ($purchase === null) {
                $pending = $this->linkableQuery()->where('annual_budget_id', $budgetId)->where('reserved_amount', '>', 0)->pluck('code');

                if ($pending->isNotEmpty()) {
                    throw ValidationException::withMessages([
                        'purchase_id' => 'No se pudo asociar la factura a una orden de compra. Selecciona una de este presupuesto: '.$pending->implode(', ').'.',
                    ]);
                }
            }

            $budget = $budgetId ? AnnualBudget::whereKey($budgetId)->lockForUpdate()->firstOrFail() : null;

            $locked->update([
                'document_number' => $documentNumber,
                'total_deducted' => $totalCents / 100,
                'purchase_id' => $purchase?->id,
                'annual_budget_id' => $budget?->id,
                'status' => InvoiceIntake::STATUS_CONFIRMED,
            ]);

            $this->settle($locked, $budget, $purchase, $documentNumber, $totalCents, $userId, $allowOverBudget, $canAuthorizeOver);

            return $locked;
        });
    }

    /**
     * Una factura vigente (borrador no cuenta) con el mismo número y proveedor ya existe.
     *
     * @throws ValidationException
     */
    private function assertNotDuplicate(InvoiceIntake $intake, string $documentNumber, ?int $supplierId): void
    {
        $duplicate = InvoiceIntake::with('purchase:id,supplier_id')
            ->where('status', InvoiceIntake::STATUS_CONFIRMED)
            ->where('document_number', $documentNumber)
            ->whereKeyNot($intake->id)
            ->get()
            ->contains(fn (InvoiceIntake $other) => ($other->purchase->supplier_id ?? ($other->raw_ocr_payload['supplier_id'] ?? null)) === $supplierId);

        if ($duplicate) {
            throw ValidationException::withMessages(['document_number' => 'Ya existe una factura vigente con ese número para el mismo proveedor.']);
        }
    }

    /**
     * Presupuesto del año en curso de la carrera detectada (null si no hay carrera o presupuesto).
     */
    private function budgetForCareer(?int $careerId): ?AnnualBudget
    {
        return $careerId === null
            ? null
            : AnnualBudget::where('career_id', $careerId)->where('year', now()->year)->first();
    }

    /**
     * Con la factura ligada: libera la reserva de la orden, consume del presupuesto de la orden el
     * total facturado (queda en `annual_budget_id` para que la anulación lo reponga) y la deja `completa`.
     * Si el total supera lo libre, `consume` lanza 422 (o 403 si pide autorizar quien no es DIR-01) y se revierte todo el ingreso.
     */
    private function settle(InvoiceIntake $intake, ?AnnualBudget $budget, ?Purchase $purchase, string $documentNumber, int $totalCents, int $userId, bool $allowOver = false, bool $canAuthorizeOver = false): void
    {
        if ($purchase !== null && $budget !== null) {
            $this->budgets->releaseReservation($budget, $purchase, $userId, $intake);
        }

        if ($budget !== null) {
            $this->budgets->consume($budget, $totalCents / 100, $intake, $userId, $purchase, $allowOver, $canAuthorizeOver);
        }

        if ($purchase === null) {
            return;
        }

        if ($purchase->status === Purchase::STATUS_COMPLETED) {
            $purchase->update(['invoice_number' => $purchase->invoice_number ?? $documentNumber]);
        } else {
            $this->purchases->completeFromArrival($purchase, ['invoice_number' => $documentNumber]);
        }

        // La factura confirmada equivale a la recepción: la solicitud de compra queda `facturada`.
        $this->purchaseRequests->markInvoiced($purchase, $userId);
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

    /**
     * Órdenes que aún esperan factura: no canceladas y sin otro ingreso vigente.
     *
     * @return Builder<Purchase>
     */
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
     * monto al presupuesto. Si la factura estaba ligada a una compra, la compra no cambia; su solicitud
     * de compra (si tiene) vuelve de `facturada` a `con_oc`.
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

        // La solicitud de compra vuelve a `con_oc` para recibir la factura correcta.
        if ($intake->purchase_id !== null) {
            $purchase = Purchase::find($intake->purchase_id);

            if ($purchase !== null) {
                $this->purchaseRequests->revertInvoiced($purchase, $userId);
            }
        }
    }

    /**
     * La compra debe ser del mismo presupuesto, no estar cancelada y conservar su reserva.
     *
     * @throws ValidationException
     */
    private function assertPurchaseBillable(Purchase $purchase, ?AnnualBudget $budget, bool $requireReservation = true): void
    {
        if ($purchase->annual_budget_id !== $budget?->id) {
            throw ValidationException::withMessages(['purchase_id' => 'La compra pertenece a otro presupuesto.']);
        }

        if ($purchase->status === Purchase::STATUS_CANCELLED || ($requireReservation && $this->budgets->cents($purchase->reserved_amount) === 0)) {
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
