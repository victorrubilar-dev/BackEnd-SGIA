<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ScanInvoiceRequest;
use App\Models\Product;
use App\Services\InvoiceOcrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

class InvoiceScanController extends Controller
{
    /**
     * Scan an invoice file and return draft products for user confirmation.
     */
    public function scan(ScanInvoiceRequest $request, InvoiceOcrService $ocrService): JsonResponse
    {
        Gate::authorize('scanInvoice', Product::class);

        $file = $request->file('invoice_file');

        try {
            $result = $ocrService->extractProductsFromInvoice($file);
        } catch (RuntimeException $e) {
            report($e);

            return response()->json(['message' => 'El servicio de lectura de facturas no está disponible. Ingresa los productos manualmente.'], 503);
        }

        return response()->json([
            'message' => 'Factura escaneada y procesada exitosamente.',
            'invoice_number' => $result['invoice_number'],
            'supplier_name' => $result['supplier_name'],
            'supplier_id' => $result['supplier_id'],
            'draft_products' => $result['items'],
        ]);
    }
}
