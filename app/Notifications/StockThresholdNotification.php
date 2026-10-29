<?php

namespace App\Notifications;

use App\Models\StockAlert;
use App\Notifications\Channels\ExpoPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StockThresholdNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public StockAlert $alert) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['broadcast', 'mail', ExpoPushChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $label = $this->alert->alert_type === StockAlert::TYPE_CRITICAL ? 'Stock crítico' : 'Stock bajo';

        return (new MailMessage)
            ->subject("{$label}: {$this->alert->product?->name}")
            ->line($this->alert->message)
            ->line("Stock actual: {$this->alert->current_stock} (mínimo {$this->alert->stock_minimo}).");
    }

    /**
     * @return array{title: string, body: string, data: array<string, mixed>}
     */
    public function toExpo(object $notifiable): array
    {
        return [
            'title' => $this->alert->alert_type === StockAlert::TYPE_CRITICAL ? 'Stock crítico' : 'Stock bajo',
            'body' => $this->alert->message,
            'data' => ['type' => 'stock_alert', 'alert_id' => $this->alert->id, 'product_id' => $this->alert->product_id],
        ];
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
