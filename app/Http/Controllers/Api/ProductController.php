<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateLocationRequest;
use App\Http\Requests\UpdateProductConditionRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Requests\UpdateProductStatusRequest;
use App\Http\Resources\CajonResource;
use App\Http\Resources\LocationResource;
use App\Http\Resources\ProductResource;
use App\Models\Cajon;
use App\Models\Product;
use App\Services\BarcodeService;
use App\Services\CodeSequenceService;
use App\Services\EquipmentPdfService;
use App\Services\EquipmentUnitService;
use App\Services\ProductService;
use App\Services\StockAlertService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ProductController extends Controller
{
    /**
     * Display a listing of products with filters.
     */
    public function index(Request $request, ProductService $products): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Product::class);

        $query = $products->applyFilters(Product::with(['supplier', 'cajon.location', 'location']), $request);

        $perPage = $this->perPage($request);

        return ProductResource::collection($query->orderByDesc('created_at')->paginate($perPage));
    }

    /**
     * Store a newly created product in storage.
     */
    public function store(
        StoreProductRequest $request,
        BarcodeService $barcodeService,
        StockAlertService $stockAlertService,
        ProductService $products,
        CodeSequenceService $codes,
        EquipmentUnitService $units
    ): JsonResponse {
        $validated = $request->validated();

        // Generación automática de código de barras único si no se proporciona
        if (empty($validated['barcode'])) {
            $type = $validated['type'] ?? Product::TYPE_EQUIPMENT;
            // Insumo: PREFIJO-CARRERA-000001 (INS-IC-000001); equipo/maqueta llevan el código en cada unidad.
            $validated['barcode'] = $type === Product::TYPE_SUPPLY
                ? $codes->forType($type, $validated['career_id'] ?? null)
                : $barcodeService->generateUniqueCode();
        }

        $validated = $products->resolveLocation($validated, $request->input('descripcion'), $request->user()?->id);

        if (! isset($validated['is_active'])) {
            $validated['is_active'] = true;
        }

        if (! isset($validated['stock_minimo'])) {
            $validated['stock_minimo'] = 5;
        }

        $validated['created_by'] = $request->user()?->id;
        $validated['updated_by'] = $request->user()?->id;

        unset($validated['units']);
        $type = $validated['type'] ?? Product::TYPE_EQUIPMENT;
        $createsUnits = $type !== Product::TYPE_SUPPLY && (int) $validated['quantity'] > 0;

        if ($createsUnits && empty($validated['cajon_id'])) {
            throw ValidationException::withMessages([
                'cajon_id' => 'Indica el cajón (cajon_id, o sala y cajon) donde se guardarán las unidades.',
            ]);
        }

        $product = DB::transaction(function () use ($validated, $createsUnits, $request, $units, $stockAlertService) {
            $product = Product::create($validated);

            if ($createsUnits) {
                // Las unidades son la fuente de verdad: recalculan contadores y alertas.
                $units->createUnits(
                    $product,
                    (int) $validated['quantity'],
                    ['cajon_id' => $validated['cajon_id']],
                    $request->user()?->id,
                    array_map(fn ($u) => $u['serial_number'] ?? null, $request->input('units', []))
                );
            } else {
                $stockAlertService->checkStock($product);
            }

            return $product;
        });

        return (new ProductResource($product->load(['supplier', 'cajon.location', 'location'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified product.
     */
    public function show(Product $product): ProductResource
    {
        Gate::authorize('view', $product);

        return new ProductResource($product->load(['supplier', 'cajon.location', 'location', 'creator', 'updater']));
    }

    /**
     * Update the specified product in storage.
     */
    public function update(
        UpdateProductRequest $request,
        Product $product,
        StockAlertService $stockAlertService,
        ProductService $products,
        EquipmentUnitService $units
    ): ProductResource {
        $validated = $request->validated();

        $newType = $validated['type'] ?? $product->type;
        $becomesUnitManaged = ! $product->hasUnits() && $newType !== Product::TYPE_SUPPLY;

        if ($product->hasUnits() && $newType === Product::TYPE_SUPPLY && $product->units()->exists()) {
            throw ValidationException::withMessages([
                'type' => 'El producto tiene unidades registradas; no puede convertirse en insumo.',
            ]);
        }

        if (($product->hasUnits() || $becomesUnitManaged) && $newType !== Product::TYPE_SUPPLY && array_key_exists('quantity', $validated) && ! $becomesUnitManaged) {
            throw ValidationException::withMessages([
                'quantity' => 'La cantidad de un equipo se calcula a partir de sus unidades.',
            ]);
        }

        $validated = $products->resolveLocation($validated, $request->input('descripcion'), $request->user()?->id);

        $validated['updated_by'] = $request->user()?->id;

        DB::transaction(function () use ($product, $validated, $becomesUnitManaged, $units, $request) {
            $product->update($validated);

            // insumo -> equipo/maqueta: las unidades nacen de los contadores actuales.
            if ($becomesUnitManaged && ! $product->units()->exists()) {
                $units->createUnitsFromCounters($product->fresh(), $request->user()?->id);
            }
        });

        // Si se actualizó la cantidad o el stock mínimo, evaluar alertas
        if (isset($validated['quantity']) || isset($validated['stock_minimo'])) {
            $stockAlertService->checkStock($product);
        }

        return new ProductResource($product->load(['supplier', 'cajon.location', 'location']));
    }

    /**
     * Remove the specified product from storage.
     */
    public function destroy(Product $product): Response|JsonResponse
    {
        Gate::authorize('delete', $product);

        if ($product->units()->exists()) {
            return $this->unprocessable('El producto tiene equipos registrados; dalos de baja en su lugar.', 'product', [
                'units_count' => $product->units()->count(),
            ]);
        }

        try {
            $product->delete();
        } catch (QueryException) {
            return $this->unprocessable('El producto está referenciado por facturas o cotizaciones y no puede eliminarse; desactívalo en su lugar.', 'product');
        }

        return response()->noContent();
    }

    /**
     * Update active status of the product.
     */
    public function updateStatus(UpdateProductStatusRequest $request, Product $product): JsonResponse
    {
        $product->forceFill([
            'is_active' => $request->boolean('is_active'),
            'updated_by' => $request->user()?->id,
        ])->save();

        return response()->json([
            'message' => 'Estado de producto actualizado exitosamente.',
            'product' => new ProductResource($product->load(['supplier', 'cajon.location', 'location'])),
        ]);
    }

    /**
     * Mueve unidades entre disponible, taller y baja (FU-05).
     *
     * PATCH /api/products/{id}/condition
     */
    public function updateCondition(UpdateProductConditionRequest $request, Product $product, ProductService $products): JsonResponse
    {
        if ($product->hasUnits()) {
            return $this->unprocessable('Los equipos cambian de estado por unidad: usa PATCH /api/equipment-units/{id}/status.', 'product_id');
        }

        $product = $products->changeCondition(
            $product,
            $request->input('action'),
            $request->integer('quantity'),
            $request->input('source'),
            $request->user()?->id
        );

        return response()->json([
            'message' => 'Estado del stock actualizado.',
            'product' => new ProductResource($product->load(['supplier', 'cajon.location', 'location'])),
        ]);
    }

    /**
     * Get location details for the product (REQ-05).
     */
    public function location(Product $product): JsonResponse
    {
        Gate::authorize('view', $product);

        $location = $product->location ?? $product->cajon?->location;
        $cajon = $product->cajon;

        if (! $location && ! $cajon) {
            return response()->json([
                'message' => 'El producto no tiene una ubicación física asignada.',
                'location' => null,
                'cajon' => null,
            ], 404);
        }

        return response()->json([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'cajon' => $cajon ? new CajonResource($cajon->load('location')) : null,
            'location' => $location ? new LocationResource($location) : null,
        ]);
    }

    /**
     * Update or assign location/cajon for the product (REQ-05).
     */
    public function updateLocation(UpdateLocationRequest $request, Product $product, ProductService $products): JsonResponse
    {
        $validated = $request->validated();
        $userId = $request->user()?->id;

        if (! empty($validated['cajon_id'])) {
            $cajon = Cajon::findOrFail($validated['cajon_id']);
            $product->forceFill([
                'cajon_id' => $cajon->id,
                'location_id' => $cajon->location_id,
                'updated_by' => $userId,
            ])->save();
        } elseif (! empty($validated['location_id'])) {
            $product->forceFill([
                'location_id' => $validated['location_id'],
                'updated_by' => $userId,
            ])->save();
        } elseif (! empty($validated['sala']) && ! empty($validated['cajon'])) {
            [$location, $cajon] = $products->findOrCreateSalaCajon(
                $validated['sala'],
                $validated['cajon'],
                $request->input('descripcion'),
                $userId
            );
            $product->forceFill([
                'cajon_id' => $cajon->id,
                'location_id' => $location->id,
                'updated_by' => $userId,
            ])->save();
        }

        return response()->json([
            'message' => 'Ubicación física actualizada exitosamente.',
            'product' => new ProductResource($product->load(['cajon.location', 'location', 'supplier'])),
        ]);
    }

    /**
     * Get barcode representation (Code128 SVG and URI) for the product (REQ-04).
     */
    public function barcode(Product $product, BarcodeService $barcodeService): JsonResponse
    {
        Gate::authorize('view', $product);

        return response()->json([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'barcode' => $product->barcode,
            'barcode_svg' => $barcodeService->generateSvg($product->barcode),
            'barcode_image_uri' => $barcodeService->generateBarcodeImageUri($product->barcode),
            'barcode_html' => $barcodeService->generateHtml($product->barcode),
        ]);
    }

    /**
     * Etiqueta PDF 50x30 mm del insumo para la impresora térmica.
     * GET /api/products/{product}/label
     */
    public function label(Product $product, BarcodeService $barcodeService, EquipmentPdfService $pdfService): SymfonyResponse
    {
        Gate::authorize('view', $product);

        if ($product->type !== Product::TYPE_SUPPLY) {
            throw ValidationException::withMessages([
                'product' => 'Solo los insumos llevan etiqueta propia; los equipos y maquetas se etiquetan por unidad.',
            ]);
        }

        return $pdfService->download(
            $pdfService->label($product->barcode, $barcodeService),
            'etiqueta-'.$product->barcode.'.pdf'
        );
    }
}
