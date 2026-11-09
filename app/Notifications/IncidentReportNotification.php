<?php

namespace App\Notifications;

use App\Models\IncidentReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso de informe de novedades: nuevo informe (pañol, administración y dirección)
 * o cambio de estado (quien lo reportó).
 */
class IncidentReportNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public IncidentReport $report, public string $message) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['broadcast', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject("Informe de novedades {$this->report->code}")->line($this->message);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'incident_report_id' => $this->report->id,
            'code' => $this->report->code,
            'status' => $this->report->status,
            'message' => $this->message,
        ];
    }
}
