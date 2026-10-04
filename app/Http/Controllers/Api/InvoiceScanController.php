<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ScanInvoiceRequest;
use App\Models\Product;
use App\Services\InvoiceOcrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class InvoiceScanController extends Controller
{
    /**
     * Scan an invoice file and return draft products for user confirmation.
     */
    public function scan(ScanInvoiceRequest $request, InvoiceOcrService $ocrService): JsonResponse
    {
        Gate::authorize('scanInvoice', Product::class);

        $file = $request->file('invoice_file');

        $result = $ocrService->extractProductsFromInvoice($file);

        return response()->json([
            'message' => 'Factura escaneada y procesada exitosamente.',
            'invoice_number' => $result['invoice_number'],
            'supplier_name' => $result['supplier_name'],
            'supplier_id' => $result['supplier_id'],
            'draft_products' => $result['items'],
        ]);
    }
}
