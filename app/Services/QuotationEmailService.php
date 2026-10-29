<?php

namespace App\Services;

use App\Mail\QuotationRequestMail;
use App\Models\Quotation;
use App\Models\Supplier;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Servicio de envío de correo automático de cotizaciones a los proveedores
 * contactados (REQ-07).
 */
class QuotationEmailService
{
    /**
     * Envía la solicitud de cotización a un proveedor. Devuelve false si el
     * proveedor no tiene correo registrado.
     */
    public function sendRequest(Quotation $quotation, Supplier $supplier): bool
    {
        if (empty($supplier->email)) {
            return false;
        }

        Mail::to($supplier->email)->send(
            new QuotationRequestMail($quotation->loadMissing('items', 'creator'), $supplier)
        );

        return true;
    }

    /**
     * Envía la solicitud a los proveedores contactados que aún no la reciben
     * y deja registrado en cada uno si salió o por qué falló, de modo que se
     * pueda reintentar solo con los pendientes.
     *
     * @return int cantidad de correos efectivamente enviados en esta llamada
     */
    public function sendToContactedSuppliers(Quotation $quotation): int
    {
        $sent = 0;

        $contacts = $quotation->suppliers()->whereNull('email_sent_at')->with('supplier')->get();

        foreach ($contacts as $contact) {
            if (! $contact->supplier) {
                continue;
            }

            try {
                if ($this->sendRequest($quotation, $contact->supplier)) {
                    $contact->forceFill(['email_sent_at' => now(), 'email_error' => null])->save();
                    $sent++;
                } else {
                    $contact->forceFill(['email_error' => 'El proveedor no tiene correo registrado.'])->save();
                }
            } catch (Throwable $e) {
                // Un proveedor con correo inválido no debe impedir el resto del envío.
                report($e);
                $contact->forceFill(['email_error' => mb_substr($e->getMessage(), 0, 250)])->save();
            }
        }

        return $sent;
    }
}
