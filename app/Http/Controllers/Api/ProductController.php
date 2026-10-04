<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateLocationRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Requests\UpdateProductStatusRequest;
use App\Http\Resources\LocationResource;
use App\Http\Resources\ProductResource;
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

        $query = Product::with(['supplier', 'location']);

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

        if ($request->filled('location_id')) {
            $query->where('location_id', $request->integer('location_id'));
        }

        // Filtro por ubicación: sala y/o cajón
        if ($request->filled('sala') || $request->filled('cajon')) {
            $query->whereHas('location', function ($q) use ($request) {
                if ($request->filled('sala')) {
                    $q->where('sala', 'ilike', '%' . $request->string('sala') . '%');
                }
                if ($request->filled('cajon')) {
                    $q->where('cajon', 'ilike', '%' . $request->string('cajon') . '%');
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

        // Asignación de ubicación si se proporcionan sala y cajón
        if (! empty($validated['sala']) && ! empty($validated['cajon'])) {
            $location = Location::firstOrCreate(
                ['sala' => $validated['sala'], 'cajon' => $validated['cajon']],
                ['descripcion' => $request->input('descripcion')]
            );
            $validated['location_id'] = $location->id;
        }

        unset($validated['sala'], $validated['cajon'], $validated['descripcion']);

        if (! isset($validated['is_active'])) {
            $validated['is_active'] = true;
        }

        if (! isset($validated['stock_minimo'])) {
            $validated['stock_minimo'] = 5;
        }

        $product = Product::create($validated);

        // Disparar chequeo de alertas de stock si corresponde
        $stockAlertService->checkStock($product);

        return (new ProductResource($product->load(['supplier', 'location'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified product.
     */
    public function show(Product $product): ProductResource
    {
        Gate::authorize('view', $product);

        return new ProductResource($product->load(['supplier', 'location']));
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
                ['sala' => $validated['sala'], 'cajon' => $validated['cajon']],
                ['descripcion' => $request->input('descripcion')]
            );
            $validated['location_id'] = $location->id;
        }

        unset($validated['sala'], $validated['cajon'], $validated['descripcion']);

        $product->update($validated);

        // Si se actualizó la cantidad o el stock mínimo, evaluar alertas
        if (isset($validated['quantity']) || isset($validated['stock_minimo'])) {
            $stockAlertService->checkStock($product);
        }

        return new ProductResource($product->load(['supplier', 'location']));
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
        $product->forceFill(['is_active' => $request->boolean('is_active')])->save();

        return response()->json([
            'message' => 'Estado de producto actualizado exitosamente.',
            'product' => new ProductResource($product->load(['supplier', 'location'])),
        ]);
    }

    /**
     * Get location details for the product (REQ-05).
     */
    public function location(Product $product): JsonResponse
    {
        Gate::authorize('view', $product);

        if (! $product->location) {
            return response()->json([
                'message' => 'El producto no tiene una ubicación física asignada.',
                'location' => null,
            ], 404);
        }

        return response()->json([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'location' => new LocationResource($product->location),
        ]);
    }

    /**
     * Update or assign location for the product (REQ-05).
     */
    public function updateLocation(UpdateLocationRequest $request, Product $product): JsonResponse
    {
        $validated = $request->validated();

        if (! empty($validated['location_id'])) {
            $product->forceFill(['location_id' => $validated['location_id']])->save();
        } elseif (! empty($validated['sala']) && ! empty($validated['cajon'])) {
            $location = Location::firstOrCreate(
                ['sala' => $validated['sala'], 'cajon' => $validated['cajon']],
                ['descripcion' => $request->input('descripcion')]
            );
            $product->forceFill(['location_id' => $location->id])->save();
        }

        return response()->json([
            'message' => 'Ubicación física actualizada exitosamente.',
            'product' => new ProductResource($product->load(['location', 'supplier'])),
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
