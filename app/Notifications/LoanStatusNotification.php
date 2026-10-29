<?php

namespace App\Notifications;

use App\Models\Loan;
use App\Notifications\Channels\ExpoPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notificación al solicitante del préstamo (docente) cuando el pañol procesa
 * su solicitud: aprobada, rechazada o material entregado (REQ-10).
 */
class LoanStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Loan $loan, public string $message) {}

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
        return (new MailMessage)
            ->subject("Préstamo {$this->loan->code}")
            ->line($this->message);
    }

    /**
     * @return array{title: string, body: string, data: array<string, mixed>}
     */
    public function toExpo(object $notifiable): array
    {
        return [
            'title' => "Préstamo {$this->loan->code}",
            'body' => $this->message,
            'data' => ['type' => 'loan_status', 'loan_id' => $this->loan->id, 'status' => $this->loan->status],
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
            'loan_id' => $this->loan->id,
            'code' => $this->loan->code,
            'status' => $this->loan->status,
            'subject' => $this->loan->subject,
            'room' => $this->loan->room,
            'loan_date' => $this->loan->loan_date?->toDateString(),
            'rejection_reason' => $this->loan->rejection_reason,
            'message' => $this->message,
        ];
    }
}
