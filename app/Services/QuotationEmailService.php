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
     * Envía la solicitud a todos los proveedores contactados por la cotización.
     *
     * @return int cantidad de correos efectivamente enviados
     */
    public function sendToContactedSuppliers(Quotation $quotation): int
    {
        $sent = 0;

        $contacts = $quotation->suppliers()->with('supplier')->get();

        foreach ($contacts as $contact) {
            if (! $contact->supplier) {
                continue;
            }

            try {
                if ($this->sendRequest($quotation, $contact->supplier)) {
                    $sent++;
                }
            } catch (Throwable $e) {
                // Un proveedor con correo inválido no debe impedir el resto del envío.
                report($e);
            }
        }

        return $sent;
    }
}
