<?php

namespace App\Services;

use App\Mail\QuotationRequestMail;
use App\Models\ProcesoCotizacion;
use App\Models\Proveedor;
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
    public function sendRequest(ProcesoCotizacion $quotation, Proveedor $supplier): bool
    {
        if (empty($supplier->email)) {
            return false;
        }

        Mail::to($supplier->email)->send(
            new QuotationRequestMail($quotation->loadMissing('cotizaciones.items.producto', 'usuario'), $supplier)
        );

        return true;
    }

    /**
     * Envía la solicitud a todos los proveedores contactados por la cotización.
     *
     * @return int cantidad de correos efectivamente enviados
     */
    public function sendToContactedSuppliers(ProcesoCotizacion $quotation): int
    {
        $sent = 0;

        $cotizaciones = $quotation->cotizaciones()->with('proveedor')->get();

        foreach ($cotizaciones as $cotizacion) {
            if (! $cotizacion->proveedor) {
                continue;
            }

            try {
                if ($this->sendRequest($quotation, $cotizacion->proveedor)) {
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
