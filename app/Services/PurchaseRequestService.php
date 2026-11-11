<?php

namespace App\Services;

use App\Exceptions\InvalidPurchaseRequestStatusTransition;
use App\Exceptions\InvalidPurchaseStatusTransition;
use App\Models\AnnualBudget;
use App\Models\Purchase;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\PurchaseRequestQuotation;
use App\Models\Supplier;
use App\Models\User;
use App\Notifications\PurchaseRequestStatusNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Solicitud de compra (nuevo flujo de compras): crear, aprobar, enviar por áreas, recibir
 * cotizaciones (archivos), elegir una, subir la OC (reserva presupuesto) y ligar la factura.
 */
class PurchaseRequestService
{
    public function __construct(
        private readonly CodeSequenceService $codes,
        private readonly QuotationEmailService $emails,
        private readonly AnnualBudgetService $budgets,
        private readonly PurchaseService $purchases,
    ) {}

    /**
     * Crea la solicitud en `borrador` con código `SC-AAAA-NNNN`.
     *
     * @param  array{annual_budget_id: int, items: array<int, array{product_id: int, quantity: int}>, notes?: string|null}  $data
     */
    public function create(array $data, User $user): PurchaseRequest
    {
        return DB::transaction(function () use ($data, $user) {
            $request = PurchaseRequest::create([
                'code' => $this->codes->forPurchaseRequest(),
                'status' => PurchaseRequest::STATUS_DRAFT,
                'annual_budget_id' => $data['annual_budget_id'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            $this->replaceItems($request, $data['items']);
            AuditLogger::record('purchase_request.created', $request, null, ['code' => $request->code, 'status' => $request->status]);

            return $request;
        });
    }

    /**
     * Edita ítems, notas o presupuesto. Solo en `borrador`.
     *
     * @param  array{annual_budget_id?: int, items?: array<int, array{product_id: int, quantity: int}>, notes?: string|null}  $data
     */
    public function update(PurchaseRequest $request, array $data, User $user): PurchaseRequest
    {
        return DB::transaction(function () use ($request, $data, $user) {
            $locked = $this->lock($request);

            if ($locked->status !== PurchaseRequest::STATUS_DRAFT) {
                throw ValidationException::withMessages(['status' => 'Solo se puede editar una solicitud en borrador.']);
            }

            $locked->fill(array_intersect_key($data, array_flip(['annual_budget_id', 'notes'])) + ['updated_by' => $user->id])->save();

            if (array_key_exists('items', $data)) {
                $this->replaceItems($locked, $data['items']);
            }

            return $locked;
        });
    }

    /** `borrador → aprobada` (solo DIR-01). Notifica a AD-01. */
    public function approve(PurchaseRequest $request, User $user): PurchaseRequest
    {
        $approved = DB::transaction(function () use ($request, $user) {
            $locked = $this->lock($request);
            $this->transition($locked, PurchaseRequest::STATUS_APPROVED, ['approved_by' => $user->id, 'approved_at' => now()], $user);

            return $locked;
        });

        $admins = User::withRole(User::ROLE_ADMIN)->where('is_active', true)->get();
        if ($admins->isNotEmpty()) {
            Notification::send($admins, new PurchaseRequestStatusNotification($approved, PurchaseRequest::STATUS_DRAFT));
        }

        return $approved;
    }

    /**
     * Envía la solicitud a todos los proveedores activos de las áreas (un proveedor en varias áreas
     * recibe un solo correo). `aprobada → enviada`.
     *
     * @param  array<int, string>  $areas
     * @return array{purchase_request: PurchaseRequest, emails_sent: int, emails_failed: int}
     */
    public function send(PurchaseRequest $request, array $areas, User $user): array
    {
        $areas = array_values(array_unique($areas));

        $locked = DB::transaction(function () use ($request, $areas, $user) {
            $locked = $this->lock($request);

            if ($locked->status !== PurchaseRequest::STATUS_APPROVED) {
                throw ValidationException::withMessages([
                    'status' => 'Solo se puede enviar una solicitud aprobada (estado actual: '.PurchaseRequestStateMachine::label($locked->status).').',
                ]);
            }

            $supplierIds = Supplier::query()->where('is_active', true)->inAreas($areas)->orderBy('id')->pluck('id');

            if ($supplierIds->isEmpty()) {
                throw ValidationException::withMessages(['areas' => 'No hay proveedores activos en las áreas seleccionadas.']);
            }

            foreach ($areas as $area) {
                $locked->areas()->create(['area' => $area]);
            }

            foreach ($supplierIds as $supplierId) {
                $locked->suppliers()->create(['supplier_id' => $supplierId]);
            }

            $this->transition($locked, PurchaseRequest::STATUS_SENT, ['sent_at' => now()], $user);

            return $locked;
        });

        // Los correos van fuera de la transacción: un fallo queda en `email_error` y se reintenta.
        $sent = $this->emails->sendToPurchaseRequestSuppliers($locked);

        return [
            'purchase_request' => $locked,
            'emails_sent' => $sent,
            'emails_failed' => $locked->suppliers()->whereNull('email_sent_at')->count(),
        ];
    }

    /**
     * Reintenta solo los correos que no salieron.
     *
     * @return array{purchase_request: PurchaseRequest, emails_sent: int, emails_failed: int}
     */
    public function resend(PurchaseRequest $request): array
    {
        if (! in_array($request->status, [PurchaseRequest::STATUS_SENT, PurchaseRequest::STATUS_QUOTED], true)) {
            throw ValidationException::withMessages(['status' => 'Solo se pueden reenviar correos de una solicitud enviada o cotizada.']);
        }

        $sent = $this->emails->sendToPurchaseRequestSuppliers($request);

        return [
            'purchase_request' => $request,
            'emails_sent' => $sent,
            'emails_failed' => $request->suppliers()->whereNull('email_sent_at')->count(),
        ];
    }

    /**
     * Registra la cotización recibida de un proveedor contactado. Al llegar a 3, `enviada → cotizada`.
     *
     * @param  array{supplier_id: int, total: string|float|int, received_at?: string|null, items: array<int, array{purchase_request_item_id: int, unit_price: string|float|int}>}  $data
     */
    public function addQuotation(PurchaseRequest $request, array $data, UploadedFile $file, User $user): PurchaseRequestQuotation
    {
        $path = $file->store('purchase-requests/quotations', 'local');

        try {
            return DB::transaction(function () use ($request, $data, $file, $path, $user) {
                $locked = $this->lock($request);

                if (! in_array($locked->status, [PurchaseRequest::STATUS_SENT, PurchaseRequest::STATUS_QUOTED], true)) {
                    throw ValidationException::withMessages([
                        'status' => 'Solo se pueden subir cotizaciones a una solicitud enviada o cotizada (estado actual: '.PurchaseRequestStateMachine::label($locked->status).').',
                    ]);
                }

                if ($locked->quotations()->where('supplier_id', $data['supplier_id'])->exists()) {
                    throw ValidationException::withMessages(['supplier_id' => 'Ya se subió una cotización de este proveedor.']);
                }

                $quotation = $locked->quotations()->create([
                    'supplier_id' => $data['supplier_id'],
                    'file_path' => $path,
                    'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'total' => $this->budgets->cents($data['total']) / 100,
                    'received_at' => $data['received_at'] ?? now(),
                    'uploaded_by' => $user->id,
                ]);

                foreach ($data['items'] as $item) {
                    $quotation->items()->create([
                        'purchase_request_item_id' => $item['purchase_request_item_id'],
                        'unit_price' => $this->budgets->cents($item['unit_price']) / 100,
                    ]);
                }

                if ($locked->status === PurchaseRequest::STATUS_SENT && $locked->quotations()->count() >= PurchaseRequest::MIN_QUOTATIONS) {
                    $this->transition($locked, PurchaseRequest::STATUS_QUOTED, [], $user);
                }

                return $quotation;
            });
        } catch (Throwable $e) {
            Storage::disk('local')->delete($path);

            throw $e;
        }
    }

    /**
     * Quita una cotización mal subida (antes de elegir). Si quedan menos de 3, `cotizada → enviada`.
     */
    public function removeQuotation(PurchaseRequest $request, PurchaseRequestQuotation $quotation, User $user): void
    {
        $path = DB::transaction(function () use ($request, $quotation, $user) {
            $locked = $this->lock($request);

            if (! in_array($locked->status, [PurchaseRequest::STATUS_SENT, PurchaseRequest::STATUS_QUOTED], true) || $locked->selected_quotation_id !== null) {
                throw ValidationException::withMessages(['quotation' => 'Solo se pueden quitar cotizaciones antes de elegir una.']);
            }

            $path = $quotation->file_path;
            $quotation->delete();

            if ($locked->status === PurchaseRequest::STATUS_QUOTED && $locked->quotations()->count() < PurchaseRequest::MIN_QUOTATIONS) {
                $this->transition($locked, PurchaseRequest::STATUS_SENT, [], $user);
            }

            return $path;
        });

        Storage::disk('local')->delete($path);
    }

    /**
     * Elige libremente una de las cotizaciones (no tiene que ser la más barata; sin motivo).
     * Exige al menos 3 subidas. Se puede cambiar la elección hasta subir la OC.
     */
    public function selectQuotation(PurchaseRequest $request, int $quotationId, User $user): PurchaseRequest
    {
        return DB::transaction(function () use ($request, $quotationId, $user) {
            $locked = $this->lock($request);
            $count = $locked->quotations()->count();

            if ($count < PurchaseRequest::MIN_QUOTATIONS) {
                throw ValidationException::withMessages([
                    'quotation_id' => 'Se necesitan al menos '.PurchaseRequest::MIN_QUOTATIONS." cotizaciones subidas para elegir (hay {$count}).",
                ]);
            }

            if ($locked->status !== PurchaseRequest::STATUS_QUOTED) {
                throw ValidationException::withMessages([
                    'status' => 'Solo se puede elegir la cotización de una solicitud cotizada (estado actual: '.PurchaseRequestStateMachine::label($locked->status).').',
                ]);
            }

            $locked->forceFill(['selected_quotation_id' => $quotationId, 'updated_by' => $user->id])->save();
            AuditLogger::record('purchase_request.quotation_selected', $locked, null, ['selected_quotation_id' => $quotationId]);

            return $locked;
        });
    }

    /**
     * Registra la OC emitida fuera del sistema: crea la `Purchase` con los precios de la cotización
     * elegida, reserva su `total` en el presupuesto y guarda el archivo. `cotizada → con_oc`.
     *
     * @throws ValidationException 422 si no hay cotización elegida o el presupuesto no alcanza
     */
    public function issueOrder(PurchaseRequest $request, string $orderNumber, UploadedFile $file, User $user): Purchase
    {
        $path = $file->store('purchase-requests/orders', 'local');

        try {
            return DB::transaction(function () use ($request, $orderNumber, $path, $user) {
                $locked = $this->lock($request);

                if ($locked->status !== PurchaseRequest::STATUS_QUOTED || $locked->selected_quotation_id === null) {
                    throw ValidationException::withMessages([
                        'quotation_id' => 'Elige una cotización antes de subir la orden de compra.',
                    ]);
                }

                $quotation = PurchaseRequestQuotation::with('items')->findOrFail($locked->selected_quotation_id);
                $prices = $quotation->items->pluck('unit_price', 'purchase_request_item_id');
                $budget = AnnualBudget::findOrFail($locked->annual_budget_id);

                $purchase = Purchase::create([
                    'code' => Purchase::generateUniqueCode(),
                    'supplier_id' => $quotation->supplier_id,
                    'created_by' => $user->id,
                    'annual_budget_id' => $budget->id,
                    'purchase_request_id' => $locked->id,
                    'order_number' => $orderNumber,
                    'order_file_path' => $path,
                    'status' => PurchaseStateMachine::STATUS_PENDING,
                    'quotation_reference' => $locked->code,
                    'notes' => $locked->notes,
                    'total' => $this->budgets->cents($quotation->total) / 100,
                ]);

                foreach ($locked->items()->with('product:id,name')->get() as $item) {
                    /** @var PurchaseRequestItem $item */
                    $purchase->items()->create([
                        'product_id' => $item->product_id,
                        'name' => $item->product->name,
                        'quantity' => $item->quantity,
                        'unit_price' => $prices[$item->id] ?? null,
                    ]);
                }

                // Lo reservado es el total digitado de la cotización elegida; la factura ajusta la diferencia.
                $this->budgets->reserve($budget, $quotation->total, $purchase, $user->id);
                $this->transition($locked, PurchaseRequest::STATUS_ORDERED, [], $user);

                return $purchase;
            });
        } catch (Throwable $e) {
            Storage::disk('local')->delete($path);

            throw $e;
        }
    }

    /**
     * Cancela la solicitud. Con OC, cancela la compra y libera la reserva.
     *
     * @throws ValidationException si la OC ya no se puede cancelar (p. ej. completa por guía)
     */
    public function cancel(PurchaseRequest $request, User $user): PurchaseRequest
    {
        return DB::transaction(function () use ($request, $user) {
            $locked = $this->lock($request);

            if (! in_array($locked->status, PurchaseRequestStateMachine::CANCELLABLE, true)) {
                throw new InvalidPurchaseRequestStatusTransition($locked->status, PurchaseRequest::STATUS_CANCELLED);
            }

            $purchase = $locked->purchase()->first();

            if ($purchase !== null) {
                try {
                    $this->purchases->cancel($purchase, $user, fromPurchaseRequest: true);
                } catch (InvalidPurchaseStatusTransition) {
                    throw ValidationException::withMessages([
                        'status' => "La orden de compra {$purchase->code} está \"{$purchase->status}\" y ya no se puede cancelar.",
                    ]);
                }
            }

            $this->transition($locked, PurchaseRequest::STATUS_CANCELLED, [], $user);

            return $locked;
        });
    }

    /**
     * OC vigente de la solicitud `$code` para ligarle una factura (`purchase_request_code` en invoice-intakes).
     * Si además se indicó `purchase_id`, debe ser la misma OC.
     *
     * @throws ValidationException
     */
    public function orderForInvoice(string $code, ?int $purchaseId = null): Purchase
    {
        $request = PurchaseRequest::where('code', $code)->first();
        $purchase = $request?->status === PurchaseRequest::STATUS_ORDERED ? $request->purchase()->first() : null;

        if ($purchase === null) {
            throw ValidationException::withMessages([
                'purchase_request_code' => "La solicitud {$code} no existe o no tiene una orden de compra pendiente de factura (debe estar \"Con OC\").",
            ]);
        }

        if ($purchaseId !== null && $purchaseId !== $purchase->id) {
            throw ValidationException::withMessages([
                'purchase_id' => "La orden de compra indicada no es la de la solicitud {$code}.",
            ]);
        }

        return $purchase;
    }

    /** Factura confirmada ligada a la OC de la solicitud: `con_oc → facturada`. */
    public function markInvoiced(Purchase $purchase, int $userId): void
    {
        $this->moveFromPurchase($purchase, PurchaseRequest::STATUS_ORDERED, PurchaseRequest::STATUS_INVOICED, $userId);
    }

    /** Factura anulada: `facturada → con_oc`. */
    public function revertInvoiced(Purchase $purchase, int $userId): void
    {
        $this->moveFromPurchase($purchase, PurchaseRequest::STATUS_INVOICED, PurchaseRequest::STATUS_ORDERED, $userId);
    }

    private function moveFromPurchase(Purchase $purchase, string $from, string $to, int $userId): void
    {
        if ($purchase->purchase_request_id === null) {
            return;
        }

        $request = PurchaseRequest::whereKey($purchase->purchase_request_id)->lockForUpdate()->first();

        if ($request !== null && $request->status === $from) {
            $request->forceFill(['status' => $to, 'updated_by' => $userId])->save();
            AuditLogger::record('purchase_request.status_changed', $request, ['status' => $from], ['status' => $to, 'code' => $request->code]);
        }
    }

    /**
     * @param  array<int, array{product_id: int, quantity: int}>  $items
     */
    private function replaceItems(PurchaseRequest $request, array $items): void
    {
        $request->items()->delete();

        foreach ($items as $item) {
            $request->items()->create(['product_id' => $item['product_id'], 'quantity' => $item['quantity']]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws InvalidPurchaseRequestStatusTransition
     */
    private function transition(PurchaseRequest $request, string $to, array $attributes, User $user): void
    {
        $from = $request->status;

        if (! PurchaseRequestStateMachine::canTransition($from, $to)) {
            throw new InvalidPurchaseRequestStatusTransition($from, $to);
        }

        $request->forceFill($attributes + ['status' => $to, 'updated_by' => $user->id])->save();
        AuditLogger::record('purchase_request.status_changed', $request, ['status' => $from], ['status' => $to, 'code' => $request->code]);
    }

    private function lock(PurchaseRequest $request): PurchaseRequest
    {
        return PurchaseRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
    }
}
