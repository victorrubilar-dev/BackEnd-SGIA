<?php

namespace App\Mail;

use App\Models\Quotation;
use App\Models\Supplier;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Solicitud formal de cotización enviada a un proveedor contactado.
 */
class QuotationRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Quotation $quotation,
        public Supplier $supplier
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Solicitud de cotización {$this->quotation->code} — SGIA INACAP",
            to: [$this->supplier->email],
        );
    }

    public function content(): Content
    {
        $this->quotation->loadMissing('items');

        return new Content(
            view: 'mail.quotation-request',
            with: [
                'quotation' => $this->quotation,
                'supplier' => $this->supplier,
                'items' => $this->quotation->items,
            ],
        );
    }
}
