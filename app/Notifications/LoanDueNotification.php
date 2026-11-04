<?php

namespace App\Notifications;

use App\Models\Loan;
use App\Notifications\Channels\ExpoPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso al docente: su préstamo vence hoy (`due`) o está atrasado (`overdue`).
 */
class LoanDueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const KIND_DUE = 'due';

    public const KIND_OVERDUE = 'overdue';

    public function __construct(public Loan $loan, public string $kind) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['broadcast', 'mail', ExpoPushChannel::class];
    }

    public function message(): string
    {
        $due = ($this->loan->due_date ?? $this->loan->loan_date)?->format('d-m-Y');

        return $this->kind === self::KIND_DUE
            ? "Tu préstamo {$this->loan->code} ({$this->loan->subject}) vence hoy. Devuélvelo en el pañol."
            : "Tu préstamo {$this->loan->code} ({$this->loan->subject}) está atrasado: venció el {$due}.";
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->kind === self::KIND_DUE ? "Préstamo {$this->loan->code} vence hoy" : "Préstamo {$this->loan->code} atrasado")
            ->line($this->message());
    }

    /**
     * @return array{title: string, body: string, data: array<string, mixed>}
     */
    public function toExpo(object $notifiable): array
    {
        return [
            'title' => $this->kind === self::KIND_DUE ? 'Préstamo por vencer' : 'Préstamo atrasado',
            'body' => $this->message(),
            'data' => ['type' => 'loan_'.$this->kind, 'loan_id' => $this->loan->id],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'loan_'.$this->kind,
            'loan_id' => $this->loan->id,
            'code' => $this->loan->code,
            'due_date' => ($this->loan->due_date ?? $this->loan->loan_date)?->toDateString(),
            'message' => $this->message(),
        ];
    }
}
