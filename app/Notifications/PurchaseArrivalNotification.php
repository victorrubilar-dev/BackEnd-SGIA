<?php

namespace App\Notifications;

use App\Models\Purchase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Notificación de orden de compra completada: la mercadería llegó y debe
 * ingresar físicamente a stock (ingreso por pañolero).
 */
class PurchaseArrivalNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Purchase $purchase)
    {
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        // En producción se añade 'fcm' / push móvil y mail. En desarrollo/testing broadcast.
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
            'purchase_id' => $this->purchase->id,
            'code' => $this->purchase->code,
            'supplier' => $this->purchase->supplier?->name,
            'status' => $this->purchase->status,
            'total' => $this->purchase->total,
            'message' => "La orden de compra {$this->purchase->code} fue marcada como completa. "
                . 'Pendiente el ingreso físico del material a stock.',
        ];
    }
}
