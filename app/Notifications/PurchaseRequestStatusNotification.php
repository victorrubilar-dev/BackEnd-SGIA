<?php

namespace App\Notifications;

use App\Models\PurchaseRequest;
use App\Services\PurchaseRequestStateMachine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso de cambio de estado de una solicitud de compra (p. ej. aprobada por DIR-01 → AD-01).
 */
class PurchaseRequestStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public PurchaseRequest $purchaseRequest,
        public string $fromStatus,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['broadcast', 'mail'];
    }

    public function message(): string
    {
        return "La solicitud de compra {$this->purchaseRequest->code} pasó de \""
            .PurchaseRequestStateMachine::label($this->fromStatus).'" a "'
            .PurchaseRequestStateMachine::label($this->purchaseRequest->status).'".';
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject("Solicitud de compra {$this->purchaseRequest->code}")->line($this->message());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'purchase_request_id' => $this->purchaseRequest->id,
            'code' => $this->purchaseRequest->code,
            'from_status' => $this->fromStatus,
            'status' => $this->purchaseRequest->status,
            'message' => $this->message(),
        ];
    }
}
