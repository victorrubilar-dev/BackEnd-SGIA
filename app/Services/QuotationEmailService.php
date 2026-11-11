<?php

namespace App\Services;

use App\Mail\PurchaseRequestQuotationMail;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestSupplier;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Envío del correo de solicitud de cotización a los proveedores contactados por una
 * solicitud de compra (`purchase_request_suppliers`), con registro de envío/error y reintento.
 */
class QuotationEmailService
{
    /**
     * Envía la solicitud de compra a los proveedores contactados que aún no la reciben y deja
     * registrado en cada uno si salió o por qué falló (reintento solo de los pendientes).
     *
     * @return int cantidad de correos efectivamente enviados en esta llamada
     */
    public function sendToPurchaseRequestSuppliers(PurchaseRequest $purchaseRequest): int
    {
        $sent = 0;
        $purchaseRequest->loadMissing(['items.product', 'creator']);

        $contacts = $purchaseRequest->suppliers()->whereNull('email_sent_at')->with('supplier')->get();

        foreach ($contacts as $contact) {
            /** @var PurchaseRequestSupplier $contact */
            $supplier = $contact->supplier;

            if (! $supplier) {
                continue;
            }

            try {
                if (empty($supplier->email)) {
                    $contact->forceFill(['email_error' => 'El proveedor no tiene correo registrado.'])->save();

                    continue;
                }

                Mail::to($supplier->email)->send(new PurchaseRequestQuotationMail($purchaseRequest, $supplier));
                $contact->forceFill(['email_sent_at' => now(), 'email_error' => null])->save();
                $sent++;
            } catch (Throwable $e) {
                report($e);
                $contact->forceFill(['email_error' => mb_substr($e->getMessage(), 0, 250)])->save();
            }
        }

        return $sent;
    }
}
