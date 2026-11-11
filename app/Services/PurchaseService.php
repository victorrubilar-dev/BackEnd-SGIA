<?php

namespace App\Services;

use App\Exceptions\InvalidPurchaseStatusTransition;
use App\Models\AnnualBudget;
use App\Models\Purchase;
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
    public function __construct(private readonly AnnualBudgetService $budgets) {}

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
        $recipients = User::withRole([User::ROLE_WAREHOUSE, User::ROLE_ADMIN])
            ->where('is_active', true)
            ->get();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new PurchaseArrivalNotification($purchase));
        }
    }

    /**
     * Crea una orden de compra histórica (sin solicitud) en `pendiente` y reserva su total.
     * No tiene ruta: la API crea las OC desde la solicitud de compra (`PurchaseRequestService::issueOrder`).
     * La usan los seeders de demostración y los tests.
     *
     * @param  array{supplier_id: int, annual_budget_id: int, items: array<int, array{product_id?: int|null, name: string, quantity: int, unit_price?: string|float|int|null}>, quotation_reference?: string|null, expected_at?: string|null, notes?: string|null}  $data
     *
     * @throws ValidationException si un ítem no tiene precio o el total supera lo libre
     */
    public function createReserved(array $data, User $user): Purchase
    {
        $unpriced = collect($data['items'])->search(fn (array $item) => ! isset($item['unit_price']));
        if ($unpriced !== false) {
            throw ValidationException::withMessages([
                "items.$unpriced.unit_price" => 'Cada producto de la orden debe tener precio unitario.',
            ]);
        }

        $budget = AnnualBudget::findOrFail($data['annual_budget_id']);

        return DB::transaction(function () use ($data, $user, $budget) {
            $items = collect($data['items']);
            $totalCents = $items->sum(fn (array $item) => $this->budgets->cents($item['unit_price']) * $item['quantity']);

            $purchase = Purchase::create([
                'code' => Purchase::generateUniqueCode(),
                'supplier_id' => $data['supplier_id'],
                'created_by' => $user->id,
                'annual_budget_id' => $budget->id,
                'status' => PurchaseStateMachine::STATUS_PENDING,
                'quotation_reference' => $data['quotation_reference'] ?? null,
                'expected_at' => $data['expected_at'] ?? null,
                'notes' => $data['notes'] ?? null,
                'total' => $totalCents / 100,
            ]);

            foreach ($items as $item) {
                $purchase->items()->create([
                    'product_id' => $item['product_id'] ?? null,
                    'name' => $item['name'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                ]);
            }

            $this->budgets->reserve($budget, $totalCents / 100, $purchase, $user->id);

            return $purchase;
        });
    }

    /**
     * Cancela la compra (`pendiente` o `en_camino`) y libera su reserva de presupuesto.
     * Una OC emitida desde una solicitud de compra se cancela cancelando la solicitud.
     *
     * @throws InvalidPurchaseStatusTransition
     * @throws ValidationException si la OC es de una solicitud y no viene de ella
     */
    public function cancel(Purchase $purchase, User $user, bool $fromPurchaseRequest = false): Purchase
    {
        return DB::transaction(function () use ($purchase, $user, $fromPurchaseRequest) {
            $locked = Purchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();

            if ($locked->purchase_request_id !== null && ! $fromPurchaseRequest) {
                throw ValidationException::withMessages([
                    'status' => 'La orden de compra pertenece a una solicitud de compra: cancela la solicitud.',
                ]);
            }

            if (! PurchaseStateMachine::canTransition($locked->status, PurchaseStateMachine::STATUS_CANCELLED)) {
                throw new InvalidPurchaseStatusTransition($locked->status, PurchaseStateMachine::STATUS_CANCELLED);
            }

            if ($locked->annual_budget_id !== null) {
                $this->budgets->releaseReservation($locked->annualBudget, $locked, $user->id);
            }

            $locked->forceFill(['status' => PurchaseStateMachine::STATUS_CANCELLED])->save();

            return $locked;
        });
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
                'status' => 'El estado debe ser: pendiente, en_camino, completa o cancelada.',
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
