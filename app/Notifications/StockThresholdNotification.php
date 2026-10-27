<?php

namespace App\Notifications;

use App\Models\Producto;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class StockThresholdNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Producto $product, public array $alert = [])
    {
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['broadcast'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'product_id' => $this->product->id,
            'product_name' => $this->product->nombre,
            'alert_type' => $this->alert['alert_type'] ?? 'critical',
            'current_stock' => $this->alert['current_stock'] ?? $this->product->stock_actual,
            'stock_minimo' => $this->alert['stock_minimo'] ?? $this->product->stock_minimo,
            'message' => $this->alert['message'] ?? "Alerta de stock para el producto {$this->product->nombre}.",
        ];
    }
}
