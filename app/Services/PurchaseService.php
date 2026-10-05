<?php

namespace App\Services;

use App\Exceptions\InvalidPurchaseStatusTransition;
use App\Models\Purchase;
use App\Models\User;
use App\Notifications\PurchaseArrivalNotification;
use Illuminate\Support\Facades\Notification;

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
}
