<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateLocationRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Requests\UpdateProductStatusRequest;
use App\Http\Resources\CajonResource;
use App\Http\Resources\LocationResource;
use App\Http\Resources\ProductResource;
use App\Models\Cajon;
use App\Models\Location;
use App\Models\Product;
use App\Services\BarcodeService;
use App\Services\StockAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ProductController extends Controller
{
    /**
     * Display a listing of products with filters.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Product::class);

        $query = Product::with(['supplier', 'cajon.location', 'location']);

        if ($request->filled('search')) {
            $search = '%' . $request->string('search') . '%';
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', $search)
                  ->orWhere('barcode', 'ilike', $search)
                  ->orWhere('description', 'ilike', $search);
            });
        }

        if ($request->filled('area')) {
            $query->where('area', $request->string('area'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->integer('supplier_id'));
        }

        if ($request->filled('cajon_id')) {
            $query->where('cajon_id', $request->integer('cajon_id'));
        }

        if ($request->filled('location_id')) {
            $locationId = $request->integer('location_id');
            $query->where(function ($q) use ($locationId) {
                $q->where('location_id', $locationId)
                  ->orWhereHas('cajon', fn ($cq) => $cq->where('location_id', $locationId));
            });
        }

        // Filtro por ubicación: sala/ubicacion y/o cajón
        if ($request->filled('sala') || $request->filled('cajon')) {
            $query->where(function ($q) use ($request) {
                if ($request->filled('sala')) {
                    $sala = '%' . $request->string('sala') . '%';
                    $q->whereHas('location', fn ($lq) => $lq->where('sala', 'ilike', $sala)->orWhere('nombre', 'ilike', $sala))
                      ->orWhereHas('cajon.location', fn ($lq) => $lq->where('sala', 'ilike', $sala)->orWhere('nombre', 'ilike', $sala));
                }
                if ($request->filled('cajon')) {
                    $cajon = '%' . $request->string('cajon') . '%';
                    $q->whereHas('cajon', fn ($cq) => $cq->where('codigo', 'ilike', $cajon))
                      ->orWhereHas('location', fn ($lq) => $lq->where('cajon', 'ilike', $cajon));
                }
            });
        }

        // Filtro de stock crítico
        if ($request->boolean('critical_only')) {
            $query->whereColumn('quantity', '<=', 'stock_minimo');
        }

        $perPage = max(1, min(100, $request->integer('per_page', 15)));
        $products = $query->orderByDesc('created_at')->paginate($perPage);

        return ProductResource::collection($products);
    }

    /**
     * Store a newly created product in storage.
     */
    public function store(
        StoreProductRequest $request,
        BarcodeService $barcodeService,
        StockAlertService $stockAlertService
    ): JsonResponse {
        $validated = $request->validated();

        // Generación automática de código de barras único si no se proporciona
        if (empty($validated['barcode'])) {
            $validated['barcode'] = $barcodeService->generateUniqueCode();
        }

        // Asignación de cajón y ubicación
        if (! empty($validated['sala']) && ! empty($validated['cajon'])) {
            $location = Location::firstOrCreate(
                ['nombre' => $validated['sala']],
                [
                    'sala' => $validated['sala'],
                    'tipo' => 'sala',
                    'descripcion' => $request->input('descripcion'),
                    'created_by' => $request->user()?->id,
                ]
            );
            $cajon = Cajon::firstOrCreate(
                ['location_id' => $location->id, 'codigo' => $validated['cajon']],
                [
                    'descripcion' => $request->input('descripcion'),
                    'created_by' => $request->user()?->id,
                ]
            );
            $validated['location_id'] = $location->id;
            $validated['cajon_id'] = $cajon->id;
        } elseif (! empty($validated['cajon_id'])) {
            $cajon = Cajon::find($validated['cajon_id']);
            if ($cajon) {
                $validated['location_id'] = $cajon->location_id;
            }
        }

        unset($validated['sala'], $validated['cajon'], $validated['descripcion']);

        if (! isset($validated['is_active'])) {
            $validated['is_active'] = true;
        }

        if (! isset($validated['stock_minimo'])) {
            $validated['stock_minimo'] = 5;
        }

        $validated['created_by'] = $request->user()?->id;
        $validated['updated_by'] = $request->user()?->id;

        $product = Product::create($validated);

        // Disparar chequeo de alertas de stock si corresponde
        $stockAlertService->checkStock($product);

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
        StockAlertService $stockAlertService
    ): ProductResource {
        $validated = $request->validated();

        if (! empty($validated['sala']) && ! empty($validated['cajon'])) {
            $location = Location::firstOrCreate(
                ['nombre' => $validated['sala']],
                [
                    'sala' => $validated['sala'],
                    'tipo' => 'sala',
                    'descripcion' => $request->input('descripcion'),
                    'created_by' => $request->user()?->id,
                ]
            );
            $cajon = Cajon::firstOrCreate(
                ['location_id' => $location->id, 'codigo' => $validated['cajon']],
                [
                    'descripcion' => $request->input('descripcion'),
                    'created_by' => $request->user()?->id,
                ]
            );
            $validated['location_id'] = $location->id;
            $validated['cajon_id'] = $cajon->id;
        } elseif (! empty($validated['cajon_id'])) {
            $cajon = Cajon::find($validated['cajon_id']);
            if ($cajon) {
                $validated['location_id'] = $cajon->location_id;
            }
        }

        unset($validated['sala'], $validated['cajon'], $validated['descripcion']);

        $validated['updated_by'] = $request->user()?->id;
        $product->update($validated);

        // Si se actualizó la cantidad o el stock mínimo, evaluar alertas
        if (isset($validated['quantity']) || isset($validated['stock_minimo'])) {
            $stockAlertService->checkStock($product);
        }

        return new ProductResource($product->load(['supplier', 'cajon.location', 'location']));
    }

    /**
     * Remove the specified product from storage.
     */
    public function destroy(Product $product): JsonResponse
    {
        Gate::authorize('delete', $product);

        $product->delete();

        return response()->json([
            'message' => 'Producto eliminado exitosamente.',
        ]);
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
    public function updateLocation(UpdateLocationRequest $request, Product $product): JsonResponse
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
            $location = Location::firstOrCreate(
                ['nombre' => $validated['sala']],
                [
                    'sala' => $validated['sala'],
                    'tipo' => 'sala',
                    'descripcion' => $request->input('descripcion'),
                    'created_by' => $userId,
                ]
            );
            $cajon = Cajon::firstOrCreate(
                ['location_id' => $location->id, 'codigo' => $validated['cajon']],
                [
                    'descripcion' => $request->input('descripcion'),
                    'created_by' => $userId,
                ]
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
}
