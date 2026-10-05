<?php

namespace App\Services;

use App\Exceptions\InvalidQuotationStatusTransition;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Quotation;
use App\Models\QuotationSupplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Servicio de cotizaciones (REQ-07): creación, envío de correos a los
 * proveedores, registro de respuestas y transiciones de estado.
 */
class QuotationService
{
    public function __construct(private QuotationEmailService $emailService)
    {
    }

    /**
     * Crea la cotización con sus ítems y proveedores contactados, y envía la
     * solicitud por correo a cada proveedor.
     *
     * @param  array<string, mixed>  $validated
     *
     * @return array{quotation: Quotation, emails_sent: int}
     */
    public function create(array $validated, User $creator): array
    {
        $quotation = DB::transaction(function () use ($validated, $creator) {
            $quotation = Quotation::create([
                'code' => Quotation::generateUniqueCode(),
                'created_by' => $creator->id,
                'status' => QuotationStateMachine::STATUS_PENDING,
                'notes' => $validated['notes'] ?? null,
                'expires_at' => $validated['expires_at'] ?? null,
            ]);

            foreach ($validated['products'] as $product) {
                $dbProduct = Product::find($product['id']);

                $quotation->items()->create([
                    'product_id' => $dbProduct?->id,
                    'name' => $product['name'] ?? $dbProduct?->name,
                    'quantity' => $product['quantity'],
                ]);
            }

            foreach ($validated['supplier_ids'] as $supplierId) {
                $quotation->suppliers()->create(['supplier_id' => $supplierId]);
            }

            return $quotation;
        });

        $emailsSent = $this->emailService->sendToContactedSuppliers($quotation);

        return [
            'quotation' => $quotation->load(['items', 'suppliers.supplier']),
            'emails_sent' => $emailsSent,
        ];
    }

    /**
     * Transiciona la cotización según su máquina de estados.
     *
     * @throws InvalidQuotationStatusTransition
     */
    public function transition(Quotation $quotation, string $toStatus): Quotation
    {
        // Operación idempotente: reenviar el estado actual no es un error.
        if ($quotation->status === $toStatus) {
            return $quotation;
        }

        if (! QuotationStateMachine::canTransition($quotation->status, $toStatus)) {
            throw new InvalidQuotationStatusTransition($quotation->status, $toStatus);
        }

        $quotation->update(['status' => $toStatus]);

        return $quotation;
    }

    /**
     * Registra la respuesta de un proveedor contactado y recalcula el estado
     * general de la cotización.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    public function recordResponse(Quotation $quotation, array $validated): QuotationSupplier
    {
        $contact = $quotation->suppliers()
            ->where('supplier_id', $validated['supplier_id'])
            ->first();

        if (! $contact) {
            throw ValidationException::withMessages([
                'supplier_id' => 'El proveedor indicado no fue contactado en esta cotización.',
            ]);
        }

        $contact->update([
            'status' => $validated['status'],
            'offer_total' => $validated['offer_total'] ?? $contact->offer_total,
            'notes' => $validated['notes'] ?? $contact->notes,
            'responded_at' => now(),
        ]);

        $this->syncStatusFromResponses($quotation);

        return $contact->fresh();
    }

    /**
     * Avanza automáticamente la cotización según las respuestas recibidas:
     *  - si algún proveedor acepta -> `aceptada`;
     *  - si todos respondieron y ninguno aceptó -> `rechazada`.
     */
    protected function syncStatusFromResponses(Quotation $quotation): void
    {
        if ($quotation->status !== QuotationStateMachine::STATUS_PENDING) {
            return;
        }

        $statuses = $quotation->suppliers()->pluck('status');

        if ($statuses->contains(QuotationSupplier::STATUS_ACCEPTED)) {
            $quotation->update(['status' => QuotationStateMachine::STATUS_ACCEPTED]);

            return;
        }

        if ($statuses->isNotEmpty() && ! $statuses->contains(QuotationSupplier::STATUS_PENDING)) {
            $quotation->update(['status' => QuotationStateMachine::STATUS_REJECTED]);
        }
    }

    /**
     * Vincula la cotización aceptada con la orden de compra generada (REQ-07 -> REQ-08).
     */
    public function markAsConverted(Quotation $quotation, Purchase $purchase): Quotation
    {
        $quotation->forceFill([
            'status' => QuotationStateMachine::STATUS_CONVERTED,
            'purchase_id' => $purchase->id,
            'converted_at' => now(),
        ])->save();

        return $quotation;
    }

    /**
     * Proveedor preferido para la orden de compra: el que aceptó la cotización
     * o, en su defecto, el primero contactado.
     */
    public function preferredSupplierId(Quotation $quotation): ?int
    {
        $contacts = $quotation->suppliers()->with('supplier')->get();

        $accepted = $contacts->first(fn (QuotationSupplier $contact) => $contact->status === QuotationSupplier::STATUS_ACCEPTED);

        return ($accepted ?? $contacts->first())?->supplier_id;
    }

    public function wasContacted(Quotation $quotation, int $supplierId): bool
    {
        return $quotation->suppliers()->where('supplier_id', $supplierId)->exists();
    }
}
