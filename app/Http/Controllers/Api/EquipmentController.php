<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\EquipmentPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class EquipmentController extends Controller
{
    /**
     * REQ-12: Ficha técnica formal del equipo en PDF (FU-05).
     *
     * GET /api/equipment/{id}/technical-sheet
     */
    public function technicalSheet(Request $request, Product $product, EquipmentPdfService $pdfService): Response
    {
        Gate::authorize('view', $product);

        $filename = 'ficha-tecnica-'.($product->barcode ?: 'equipo-'.$product->id).'.pdf';

        return $pdfService->download($pdfService->technicalSheet($product, $request->user()), $filename);
    }
}
