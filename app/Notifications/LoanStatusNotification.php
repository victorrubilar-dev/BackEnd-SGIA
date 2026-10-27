<?php

namespace App\Notifications;

use App\Models\Prestamo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Notificación al solicitante del préstamo (docente) cuando el pañol procesa
 * su solicitud: aprobada, rechazada o material entregado (REQ-10).
 */
class LoanStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Prestamo $loan, public string $message)
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
            'loan_id' => $this->loan->id,
            'code' => $this->loan->code,
            'status' => $this->loan->status,
            'subject' => $this->loan->subject,
            'room' => $this->loan->room,
            'loan_date' => $this->loan->loan_date?->toDateString(),
            'rejection_reason' => $this->loan->observaciones,
            'message' => $this->message,
        ];
    }
}
