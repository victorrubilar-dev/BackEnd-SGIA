<?php

namespace App\Services;

use App\Models\Producto;
use App\Models\Usuario;
use App\Notifications\StockThresholdNotification;
use Illuminate\Support\Facades\Notification;

class StockAlertService
{
    /**
     * Check product stock and dispatch alerts if thresholds are reached.
     */
    public function checkStock(Producto $product): ?array
    {
        $currentStock = (int) $product->stock_actual;
        $minStock = (int) $product->stock_minimo;

        if ($currentStock <= $minStock) {
            $alert = [
                'product_id' => $product->id,
                'alert_type' => 'critical',
                'current_stock' => $currentStock,
                'stock_minimo' => $minStock,
                'message' => "Stock crítico: el producto {$product->nombre} ha alcanzado su stock mínimo ({$currentStock}/{$minStock}).",
            ];

            $this->notifyStaff($product, $alert);

            return $alert;
        }

        if ($currentStock <= ($minStock + 5)) {
            $alert = [
                'product_id' => $product->id,
                'alert_type' => 'warning',
                'current_stock' => $currentStock,
                'stock_minimo' => $minStock,
                'message' => "Advertencia de stock: el producto {$product->nombre} está a 5 o menos unidades de su stock mínimo ({$currentStock}/{$minStock}).",
            ];

            $this->notifyStaff($product, $alert);

            return $alert;
        }

        return null;
    }

    /**
     * Notify administrators (AD-01) and warehouse keepers (PAN-01).
     */
    protected function notifyStaff(Producto $product, array $alert): void
    {
        $recipients = Usuario::whereHas('rol', function ($q) {
            $q->whereIn('nombre', ['Administrador', 'Pañol', 'Ayudante de pañol', 'Director de carrera']);
        })->where('activo', true)->get();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new StockThresholdNotification($product, $alert));
        }
    }
}
