<?php

namespace App\Notifications;

use App\Models\Quotation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso de que una cotización cambió de estado (se envía en cada cambio).
 * Si respondieron menos proveedores que el mínimo recomendado, lo indica.
 */
class QuotationStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Quotation $quotation,
        public string $fromStatus,
        public int $respondedSuppliers,
        public int $minSuppliers,
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
        $message = "La cotización {$this->quotation->code} pasó de \"{$this->fromStatus}\" a \"{$this->quotation->status}\".";

        if ($this->respondedSuppliers < $this->minSuppliers) {
            $message .= " Solo {$this->respondedSuppliers} de {$this->minSuppliers} proveedores recomendados han respondido.";
        }

        return $message;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject("Cotización {$this->quotation->code}")->line($this->message());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'quotation_id' => $this->quotation->id,
            'code' => $this->quotation->code,
            'from_status' => $this->fromStatus,
            'status' => $this->quotation->status,
            'responded_suppliers' => $this->respondedSuppliers,
            'min_suppliers' => $this->minSuppliers,
            'meets_min_quotations' => $this->respondedSuppliers >= $this->minSuppliers,
            'message' => $this->message(),
        ];
    }
}
