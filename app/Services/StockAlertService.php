<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockAlert;
use App\Models\User;
use App\Notifications\StockThresholdNotification;
use Illuminate\Support\Facades\Notification;

class StockAlertService
{
    /**
     * Check product stock and dispatch alerts if thresholds are reached.
     */
    public function checkStock(Product $product): ?StockAlert
    {
        $currentStock = $product->quantity;
        $minStock = $product->stock_minimo;

        // Caso 1: Stock Crítico (<= stock_minimo)
        if ($currentStock <= $minStock) {
            $alert = StockAlert::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'alert_type' => StockAlert::TYPE_CRITICAL,
                    'is_resolved' => false,
                ],
                [
                    'current_stock' => $currentStock,
                    'stock_minimo' => $minStock,
                    'message' => "Stock crítico: el producto {$product->name} ha alcanzado su stock mínimo ({$currentStock}/{$minStock}).",
                ]
            );

            $this->notifyStaff($alert);

            return $alert;
        }

        // Caso 2: Advertencia de Stock (<= stock_minimo + 5)
        if ($currentStock <= ($minStock + 5)) {
            $alert = StockAlert::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'alert_type' => StockAlert::TYPE_WARNING,
                    'is_resolved' => false,
                ],
                [
                    'current_stock' => $currentStock,
                    'stock_minimo' => $minStock,
                    'message' => "Advertencia de stock: el producto {$product->name} está a 5 o menos unidades de su stock mínimo ({$currentStock}/{$minStock}).",
                ]
            );

            $this->notifyStaff($alert);

            return $alert;
        }

        // Caso 3: Stock recuperado (> stock_minimo + 5), resolver alertas previas
        StockAlert::where('product_id', $product->id)
            ->where('is_resolved', false)
            ->update(['is_resolved' => true]);

        return null;
    }

    /**
     * Notify administrators (AD-01) and warehouse keepers (PAN-01).
     */
    protected function notifyStaff(StockAlert $alert): void
    {
        $recipients = User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_WAREHOUSE])
            ->where('is_active', true)
            ->get();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new StockThresholdNotification($alert));
        }
    }
}
