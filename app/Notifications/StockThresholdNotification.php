<?php

namespace App\Notifications;

use App\Models\StockAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StockThresholdNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public StockAlert $alert)
    {
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        // En producción se añade 'fcm' / push móvil y mail. En testing/desarrollo registramos el canal estándar.
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
            'alert_id' => $this->alert->id,
            'product_id' => $this->alert->product_id,
            'product_name' => $this->alert->product?->name,
            'alert_type' => $this->alert->alert_type,
            'current_stock' => $this->alert->current_stock,
            'stock_minimo' => $this->alert->stock_minimo,
            'message' => $this->alert->message,
        ];
    }
}
