<?php

namespace App\Services;

use App\Exceptions\InvalidQuotationStatusTransition;
use App\Models\Carrera;
use App\Models\Cotizacion;
use App\Models\CotizacionItem;
use App\Models\ProcesoCotizacion;
use App\Models\Usuario;
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
     */
    public function create(array $validated, Usuario $creator): array
    {
        $quotation = DB::transaction(function () use ($validated, $creator) {
            $carreraId = $creator->carreras()->first()?->id ?? Carrera::first()?->id ?? 1;
            $titulo = $validated['title'] ?? $validated['titulo'] ?? $validated['notes'] ?? ('Cotización ' . date('d/m/Y H:i'));

            $proceso = ProcesoCotizacion::create([
                'usuario_id' => $creator->id,
                'carrera_id' => $carreraId,
                'titulo_proceso' => $titulo,
                'estado' => QuotationStateMachine::STATUS_PENDING,
            ]);

            foreach ($validated['supplier_ids'] as $supplierId) {
                $cotizacion = Cotizacion::create([
                    'proceso_cotizacion_id' => $proceso->id,
                    'proveedor_id' => $supplierId,
                    'estado' => 'pendiente',
                    'fecha_envio' => now(),
                ]);

                foreach ($validated['products'] as $product) {
                    CotizacionItem::create([
                        'cotizacion_id' => $cotizacion->id,
                        'producto_id' => $product['id'],
                        'cantidad_solicitada' => $product['quantity'],
                    ]);
                }
            }

            return $proceso;
        });

        $emailsSent = $this->emailService->sendToContactedSuppliers($quotation);

        return [
            'quotation' => $quotation->load(['cotizaciones.proveedor', 'cotizaciones.items.producto']),
            'emails_sent' => $emailsSent,
        ];
    }

    /**
     * Transiciona la cotización según su máquina de estados.
     */
    public function transition(ProcesoCotizacion $quotation, string $toStatus): ProcesoCotizacion
    {
        if ($quotation->status === $toStatus) {
            return $quotation;
        }

        if (! QuotationStateMachine::canTransition($quotation->status, $toStatus)) {
            throw new InvalidQuotationStatusTransition($quotation->status, $toStatus);
        }

        $quotation->update(['estado' => $toStatus]);

        return $quotation;
    }

    /**
     * Registra la respuesta de un proveedor contactado y recalcula el estado
     * general de la cotización.
     */
    public function recordResponse(ProcesoCotizacion $quotation, array $validated): Cotizacion
    {
        $cotizacion = $quotation->cotizaciones()
            ->where('proveedor_id', $validated['supplier_id'])
            ->first();

        if (! $cotizacion) {
            throw ValidationException::withMessages([
                'supplier_id' => 'El proveedor indicado no fue contactado en esta cotización.',
            ]);
        }

        $cotizacion->update([
            'estado' => $validated['status'],
            'monto_total' => $validated['offer_total'] ?? $cotizacion->monto_total,
            'numero_cotizacion' => $validated['notes'] ?? $cotizacion->numero_cotizacion,
        ]);

        $this->syncStatusFromResponses($quotation);

        return $cotizacion->fresh(['proveedor', 'items.producto']);
    }

    /**
     * Avanza automáticamente la cotización según las respuestas recibidas.
     */
    protected function syncStatusFromResponses(ProcesoCotizacion $quotation): void
    {
        if ($quotation->status !== QuotationStateMachine::STATUS_PENDING) {
            return;
        }

        $statuses = $quotation->cotizaciones()->pluck('estado');

        if ($statuses->contains('aceptada')) {
            $quotation->update(['estado' => QuotationStateMachine::STATUS_ACCEPTED]);

            return;
        }

        if ($statuses->isNotEmpty() && ! $statuses->contains('pendiente')) {
            $quotation->update(['estado' => QuotationStateMachine::STATUS_REJECTED]);
        }
    }

    /**
     * Vincula la cotización aceptada con la orden de compra generada.
     */
    public function markAsConverted(ProcesoCotizacion $quotation, ProcesoCotizacion $purchase): ProcesoCotizacion
    {
        $quotation->update([
            'estado' => QuotationStateMachine::STATUS_CONVERTED,
        ]);

        return $quotation;
    }

    /**
     * Proveedor preferido para la orden de compra.
     */
    public function preferredSupplierId(ProcesoCotizacion $quotation): ?int
    {
        $contacts = $quotation->cotizaciones()->with('proveedor')->get();
        $accepted = $contacts->first(fn ($contact) => $contact->estado === 'aceptada');

        return ($accepted ?? $contacts->first())?->proveedor_id;
    }

    public function wasContacted(ProcesoCotizacion $quotation, int $supplierId): bool
    {
        return $quotation->cotizaciones()->where('proveedor_id', $supplierId)->exists();
    }
}
