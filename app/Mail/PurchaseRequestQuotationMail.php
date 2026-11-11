<?php

namespace App\Mail;

use App\Models\PurchaseRequest;
use App\Models\Supplier;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Solicitud de cotización de una solicitud de compra, enviada a cada proveedor de las áreas elegidas.
 */
class PurchaseRequestQuotationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public PurchaseRequest $purchaseRequest,
        public Supplier $supplier
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Solicitud de cotización {$this->purchaseRequest->code} — SGIA INACAP",
            to: [$this->supplier->email],
        );
    }

    public function content(): Content
    {
        $this->purchaseRequest->loadMissing(['items.product', 'creator']);

        return new Content(
            view: 'mail.purchase-request-quotation',
            with: [
                'purchaseRequest' => $this->purchaseRequest,
                'supplier' => $this->supplier,
                'items' => $this->purchaseRequest->items,
            ],
        );
    }
}
