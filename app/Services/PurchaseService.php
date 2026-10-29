<?php

namespace App\Services;

use App\Exceptions\InvalidPurchaseStatusTransition;
use App\Models\Purchase;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use App\Notifications\PurchaseArrivalNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Servicio de transición de estados para órdenes de compra (REQ-08).
 *
 * Aplica la máquina de estados pendiente -> en_camino -> completa y, al
 * completar la orden, notifica al pañolero (PAN-01) para el ingreso físico
 * del material a stock.
 */
class PurchaseService
{
    /**
     * Transiciona la orden a otro estado respetando la máquina de estados.
     *
     * @param  array<string, mixed>  $attributes  datos adicionales a persistir (ej. totales, fechas)
     *
     * @throws InvalidPurchaseStatusTransition
     */
    public function transition(Purchase $purchase, string $toStatus, array $attributes = []): Purchase
    {
        if (! PurchaseStateMachine::canTransition($purchase->status, $toStatus)) {
            throw new InvalidPurchaseStatusTransition($purchase->status, $toStatus);
        }

        $this->persist($purchase, $attributes + ['status' => $toStatus]);

        return $purchase;
    }

    /**
     * Marca la orden como `completa` a partir del escaneo de la guía de
     * despacho / factura de llegada.
     *
     * El arribo físico de la mercadería implica que la orden fue despachada,
     * por lo que se permite completarla incluso si aún estaba en `pendiente`
     * (no se registró previamente la salida `en_camino`). Única excepción a la
     * transición lineal de la máquina de estados.
     *
     * @param  array<string, mixed>  $attributes  número de guía, factura y documento escaneado
     *
     * @throws InvalidPurchaseStatusTransition
     */
    public function completeFromArrival(Purchase $purchase, array $attributes = []): Purchase
    {
        if ($purchase->status === PurchaseStateMachine::STATUS_COMPLETED) {
            throw new InvalidPurchaseStatusTransition(
                $purchase->status,
                PurchaseStateMachine::STATUS_COMPLETED,
                "La orden de compra {$purchase->code} ya se encuentra en estado \"completa\"."
            );
        }

        if (! in_array($purchase->status, [PurchaseStateMachine::STATUS_PENDING, PurchaseStateMachine::STATUS_IN_TRANSIT], true)) {
            throw new InvalidPurchaseStatusTransition($purchase->status, PurchaseStateMachine::STATUS_COMPLETED);
        }

        if (empty($attributes['received_at'])) {
            $attributes['received_at'] = now();
        }

        $this->persist($purchase, $attributes + ['status' => PurchaseStateMachine::STATUS_COMPLETED]);

        return $purchase;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function persist(Purchase $purchase, array $attributes): void
    {
        $purchase->fill($attributes)->save();

        if ($purchase->status === PurchaseStateMachine::STATUS_COMPLETED && ! $purchase->wasRecentlyCreated) {
            if (empty($purchase->received_at)) {
                $purchase->forceFill(['received_at' => now()])->save();
            }

            $this->notifyWarehouseArrival($purchase);
        }
    }

    /**
     * Notifica al pañolero (PAN-01) y al administrador (AD-01) que la orden
     * llegó y debe ingresarse físicamente a stock.
     */
    protected function notifyWarehouseArrival(Purchase $purchase): void
    {
        $recipients = User::whereIn('role', [User::ROLE_WAREHOUSE, User::ROLE_ADMIN])
            ->where('is_active', true)
            ->get();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new PurchaseArrivalNotification($purchase));
        }
    }

    /**
     * Crea una orden de compra en `pendiente`, desde cero (proveedor + ítems)
     * o a partir de una cotización aceptada, que queda como `convertida`.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    public function create(array $validated, User $user, QuotationService $quotationService): Purchase
    {
        $quotation = null;

        if (! empty($validated['quotation_id'])) {
            $quotation = Quotation::with('items')->findOrFail($validated['quotation_id']);

            if ($quotation->status !== Quotation::STATUS_ACCEPTED) {
                throw ValidationException::withMessages([
                    'quotation_id' => 'Solo se puede generar una orden de compra desde una cotización aceptada '
                        ."(estado actual: \"{$quotation->status}\").",
                ]);
            }

            if (isset($validated['supplier_id']) && ! $quotationService->wasContacted($quotation, (int) $validated['supplier_id'])) {
                throw ValidationException::withMessages([
                    'supplier_id' => 'El proveedor debe estar entre los contactados en la cotización.',
                ]);
            }
        }

        $items = $validated['items']
            ?? $quotation?->items->map(fn (QuotationItem $item) => [
                'product_id' => $item->product_id,
                'name' => $item->name,
                'quantity' => $item->quantity,
            ])->all();

        $supplierId = $validated['supplier_id']
            ?? ($quotation ? $quotationService->preferredSupplierId($quotation) : null);

        if ($quotation && empty($validated['items']) && $quotation->items->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => 'La cotización no tiene ítems para copiar en la orden de compra.',
            ]);
        }

        if ($quotation && $supplierId === null) {
            throw ValidationException::withMessages([
                'supplier_id' => 'La cotización no tiene proveedores contactados para asignar a la orden de compra.',
            ]);
        }

        $purchase = DB::transaction(function () use ($validated, $user, $quotation, $quotationService, $items, $supplierId) {
            $items = collect($items);

            $total = $items->contains(fn (array $item) => ! isset($item['unit_price']))
                ? null
                : $items->sum(fn (array $item) => $item['unit_price'] * $item['quantity']);

            $purchase = Purchase::create([
                'code' => Purchase::generateUniqueCode(),
                'supplier_id' => $supplierId,
                'created_by' => $user->id,
                'quotation_id' => $quotation?->id,
                'status' => PurchaseStateMachine::STATUS_PENDING,
                'quotation_reference' => $validated['quotation_reference'] ?? $quotation?->code,
                'expected_at' => $validated['expected_at'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'total' => $total,
            ]);

            foreach ($items as $item) {
                $purchase->items()->create([
                    'product_id' => $item['product_id'] ?? null,
                    'name' => $item['name'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'] ?? null,
                ]);
            }

            if ($quotation) {
                // La cotización aprobada queda vinculada y convertida en OC.
                $quotationService->markAsConverted($quotation, $purchase);
            }

            return $purchase;
        });

        return $purchase;
    }

    /**
     * Filtros del listado de compras: estado, proveedor, cotización y búsqueda.
     *
     * @throws ValidationException si el estado no es válido
     */
    public function applyFilters(Builder $query, Request $request): Builder
    {
        $status = $request->input('status', $request->input('estado'));

        if ($status !== null && ! PurchaseStateMachine::isValidStatus((string) $status)) {
            throw ValidationException::withMessages([
                'status' => 'El estado debe ser: pendiente, en_camino o completa.',
            ]);
        }

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->integer('supplier_id'));
        }

        if ($request->filled('quotation_id')) {
            $query->where('quotation_id', $request->integer('quotation_id'));
        }

        if ($request->filled('search')) {
            $search = '%'.$request->string('search').'%';
            $query->where(function ($q) use ($search) {
                $q->where('code', 'ilike', $search)
                    ->orWhere('guide_number', 'ilike', $search)
                    ->orWhere('invoice_number', 'ilike', $search);
            });
        }

        return $query;
    }
}
