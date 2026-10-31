<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEquipmentUnitRequest;
use App\Http\Requests\UpdateEquipmentUnitRequest;
use App\Http\Requests\UpdateEquipmentUnitStatusRequest;
use App\Http\Resources\EquipmentUnitResource;
use App\Models\EquipmentUnit;
use App\Models\Product;
use App\Services\BarcodeService;
use App\Services\EquipmentPdfService;
use App\Services\EquipmentUnitService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class EquipmentUnitController extends Controller
{
    /** Relaciones de listado (evita N+1). */
    private const LIST_RELATIONS = ['product', 'cajon.location.storeroom'];

    /**
     * GET /api/products/{product}/units
     */
    public function indexForProduct(Request $request, Product $product, EquipmentUnitService $units): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', EquipmentUnit::class);

        $query = $units->applyFilters($product->units()->with(self::LIST_RELATIONS)->getQuery(), $request);

        return EquipmentUnitResource::collection($query->orderBy('id')->paginate($this->perPage($request)));
    }

    /**
     * POST /api/products/{product}/units
     */
    public function storeForProduct(StoreEquipmentUnitRequest $request, Product $product, EquipmentUnitService $units): JsonResponse
    {
        $created = $units->createUnits(
            $product,
            $request->integer('count', 1),
            $request->safe()->only(['cajon_id', 'code', 'serial_number']),
            $request->user()?->id,
            array_values($request->input('serial_numbers', []))
        );

        $created = (new EloquentCollection($created->all()))->load(self::LIST_RELATIONS);

        if (! $request->has('count')) {
            return (new EquipmentUnitResource($created->first()))->response()->setStatusCode(201);
        }

        return EquipmentUnitResource::collection($created)->response()->setStatusCode(201);
    }

    /**
     * GET /api/equipment-units
     */
    public function index(Request $request, EquipmentUnitService $units): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', EquipmentUnit::class);

        $query = $units->applyFilters(EquipmentUnit::with(self::LIST_RELATIONS), $request);

        return EquipmentUnitResource::collection($query->orderBy('id')->paginate($this->perPage($request)));
    }

    /**
     * GET /api/equipment-units/by-code/{code}
     */
    public function byCode(string $code): EquipmentUnitResource
    {
        Gate::authorize('viewAny', EquipmentUnit::class);

        return new EquipmentUnitResource($this->loadDetail(EquipmentUnit::where('code', $code)->firstOrFail()));
    }

    /**
     * GET /api/equipment-units/{equipmentUnit}
     */
    public function show(EquipmentUnit $equipmentUnit): EquipmentUnitResource
    {
        Gate::authorize('view', $equipmentUnit);

        return new EquipmentUnitResource($this->loadDetail($equipmentUnit));
    }

    /**
     * PATCH /api/equipment-units/{equipmentUnit}
     */
    public function update(UpdateEquipmentUnitRequest $request, EquipmentUnit $equipmentUnit): EquipmentUnitResource
    {
        $equipmentUnit->update($request->validated() + ['updated_by' => $request->user()?->id]);

        return new EquipmentUnitResource($this->loadDetail($equipmentUnit));
    }

    /**
     * PATCH /api/equipment-units/{equipmentUnit}/status
     */
    public function updateStatus(UpdateEquipmentUnitStatusRequest $request, EquipmentUnit $equipmentUnit, EquipmentUnitService $units): EquipmentUnitResource
    {
        $units->changeStatus($equipmentUnit, $request->string('status')->toString(), $request->user()?->id);

        return new EquipmentUnitResource($this->loadDetail($equipmentUnit));
    }

    /**
     * DELETE /api/equipment-units/{equipmentUnit}
     */
    public function destroy(EquipmentUnit $equipmentUnit, EquipmentUnitService $units): Response|JsonResponse
    {
        Gate::authorize('delete', $equipmentUnit);

        if ($units->hasHistory($equipmentUnit)) {
            return response()->json([
                'message' => 'La unidad tiene préstamos, mantenciones o informes, o está prestada; dala de baja en su lugar.',
            ], 422);
        }

        $product = $equipmentUnit->product;
        $equipmentUnit->delete();
        $units->syncProductCounters($product);

        return response()->noContent();
    }

    /**
     * GET /api/equipment-units/{equipmentUnit}/barcode
     */
    public function barcode(EquipmentUnit $equipmentUnit, BarcodeService $barcodeService): JsonResponse
    {
        Gate::authorize('view', $equipmentUnit);

        return response()->json([
            'equipment_unit_id' => $equipmentUnit->id,
            'product_id' => $equipmentUnit->product_id,
            'code' => $equipmentUnit->code,
            'barcode_svg' => $barcodeService->generateSvg($equipmentUnit->code),
            'barcode_image_uri' => $barcodeService->generateBarcodeImageUri($equipmentUnit->code),
            'barcode_html' => $barcodeService->generateHtml($equipmentUnit->code),
        ]);
    }

    /**
     * GET /api/equipment-units/{equipmentUnit}/technical-sheet
     */
    public function technicalSheet(EquipmentUnit $equipmentUnit, EquipmentPdfService $pdfService): SymfonyResponse
    {
        Gate::authorize('view', $equipmentUnit);

        return $pdfService->download(
            $pdfService->unitTechnicalSheet($equipmentUnit),
            'hoja-de-vida-'.$equipmentUnit->code.'.pdf'
        );
    }

    private function loadDetail(EquipmentUnit $unit): EquipmentUnit
    {
        return $unit->load([...self::LIST_RELATIONS, 'loanItems.loan']);
    }
}
