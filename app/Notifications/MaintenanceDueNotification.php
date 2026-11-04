<?php

namespace App\Notifications;

use App\Notifications\Channels\ExpoPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Resumen diario al pañol (PAN-01): unidades con mantención vencida o próxima.
 */
class MaintenanceDueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, array{id: int, code: string, product: string, next_maintenance_at: string}>  $units
     */
    public function __construct(public array $units, public int $withinDays) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['broadcast', 'mail', ExpoPushChannel::class];
    }

    public function message(): string
    {
        $count = count($this->units);

        return $count === 1
            ? "La unidad {$this->units[0]['code']} ({$this->units[0]['product']}) tiene mantención el {$this->units[0]['next_maintenance_at']}."
            : "{$count} unidades tienen la mantención vencida o en los próximos {$this->withinDays} días.";
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject('Mantenciones próximas')->line($this->message());

        foreach (array_slice($this->units, 0, 30) as $unit) {
            $mail->line("• {$unit['code']} — {$unit['product']}: {$unit['next_maintenance_at']}");
        }

        return $mail->line('Detalle en GET /api/equipment-units?maintenance_due=1.');
    }

    /**
     * @return array{title: string, body: string, data: array<string, mixed>}
     */
    public function toExpo(object $notifiable): array
    {
        return [
            'title' => 'Mantenciones próximas',
            'body' => $this->message(),
            'data' => ['type' => 'maintenance_due', 'unit_ids' => array_column($this->units, 'id')],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['type' => 'maintenance_due', 'units' => $this->units, 'message' => $this->message()];
    }
}
