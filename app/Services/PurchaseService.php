<?php

namespace App\Services;

use App\Exceptions\InvalidPurchaseStatusTransition;
use App\Models\ProcesoCotizacion;
use App\Models\Usuario;
use App\Notifications\PurchaseArrivalNotification;
use Illuminate\Support\Facades\Notification;

class PurchaseService
{
    /**
     * Transiciona la orden a otro estado respetando la máquina de estados.
     */
    public function transition(ProcesoCotizacion $purchase, string $toStatus, array $attributes = []): ProcesoCotizacion
    {
        if (! PurchaseStateMachine::canTransition($purchase->status, $toStatus)) {
            throw new InvalidPurchaseStatusTransition($purchase->status, $toStatus);
        }

        $this->persist($purchase, $attributes + ['estado' => $toStatus]);

        return $purchase;
    }

    /**
     * Marca la orden como `completa` a partir del escaneo de la guía de despacho.
     */
    public function completeFromArrival(ProcesoCotizacion $purchase, array $attributes = []): ProcesoCotizacion
    {
        if ($purchase->status === PurchaseStateMachine::STATUS_COMPLETED) {
            throw new InvalidPurchaseStatusTransition(
                $purchase->status,
                PurchaseStateMachine::STATUS_COMPLETED,
                "La orden de compra {$purchase->code} ya se encuentra en estado \"completa\"."
            );
        }

        $this->persist($purchase, $attributes + ['estado' => PurchaseStateMachine::STATUS_COMPLETED]);

        return $purchase;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function persist(ProcesoCotizacion $purchase, array $attributes): void
    {
        if (isset($attributes['status'])) {
            $attributes['estado'] = $attributes['status'];
            unset($attributes['status']);
        }

        $purchase->fill($attributes)->save();

        if ($purchase->status === PurchaseStateMachine::STATUS_COMPLETED && ! $purchase->wasRecentlyCreated) {
            $this->notifyWarehouseArrival($purchase);
        }
    }

    /**
     * Notifica al pañolero (PAN-01) y al administrador (AD-01).
     */
    protected function notifyWarehouseArrival(ProcesoCotizacion $purchase): void
    {
        $recipients = Usuario::whereHas('rol', function ($q) {
            $q->whereIn('nombre', ['Administrador', 'Pañol', 'Ayudante de pañol']);
        })->where('activo', true)->get();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new PurchaseArrivalNotification($purchase));
        }
    }
}
