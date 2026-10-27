<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Carrera;
use App\Models\Categoria;
use App\Models\Equipo;
use App\Models\Estante;
use App\Models\Producto;
use App\Services\BarcodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ProductController extends Controller
{
    /**
     * Display a listing of products with filters.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Producto::class);

        $query = Producto::with(['categoria', 'carrera', 'equipos.estante.sala']);

        if ($request->filled('search')) {
            $search = '%' . $request->string('search') . '%';
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'ilike', $search)
                  ->orWhere('sku', 'ilike', $search)
                  ->orWhere('tipo', 'ilike', $search);
            });
        }

        if ($request->filled('carrera')) {
            $carreraCode = $request->string('carrera');
            $query->whereHas('carrera', fn ($cq) => $cq->where('codigo', $carreraCode));
        }

        if ($request->filled('categoria_id')) {
            $query->where('categoria_id', $request->integer('categoria_id'));
        }

        if ($request->boolean('critical_only')) {
            $query->whereColumn('stock_actual', '<=', 'stock_minimo');
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
        BarcodeService $barcodeService
    ): JsonResponse {
        $validated = $request->validated();

        $nombre = $validated['nombre'] ?? $validated['name'];
        $stock = (int) ($validated['stock_actual'] ?? $validated['quantity'] ?? 0);
        $sku = $validated['sku'] ?? $validated['barcode'] ?? null;
        $tipo = $validated['tipo'] ?? 'equipo';
        $categoriaId = $validated['categoria_id'] ?? Categoria::first()?->id ?? 1;
        $carreraId = $validated['carrera_id'] ?? $request->user()?->carreras->first()?->id ?? Carrera::first()?->id ?? 1;

        $carrera = Carrera::find($carreraId);
        $carreraCodigo = $carrera ? $carrera->codigo : 'ISD';

        $prefijo = match (strtolower($tipo)) {
            'insumo' => 'INS',
            'maqueta' => 'MAQ',
            default => 'PRO',
        };

        if (empty($sku)) {
            try {
                $result = DB::select("SELECT fn_generar_codigo_sgia(?, ?) as codigo", [$prefijo, $carreraCodigo]);
                $sku = $result[0]->codigo ?? $barcodeService->generateUniqueCode();
            } catch (\Throwable) {
                $sku = $barcodeService->generateUniqueCode();
            }
        }

        $producto = Producto::create([
            'nombre' => $nombre,
            'sku' => $sku,
            'tipo' => $tipo,
            'categoria_id' => $categoriaId,
            'carrera_id' => $carreraId,
            'stock_actual' => $stock,
            'stock_minimo' => $validated['stock_minimo'] ?? 5,
            'precio_referencia' => $validated['precio_referencia'] ?? null,
            'foto_url' => $validated['foto_url'] ?? $validated['photo_url'] ?? null,
        ]);

        return (new ProductResource($producto->load(['categoria', 'carrera', 'equipos'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified product.
     */
    public function show(Producto $product): ProductResource
    {
        Gate::authorize('view', $product);

        return new ProductResource($product->load(['categoria', 'carrera', 'equipos.estante.sala']));
    }

    /**
     * Update the specified product in storage.
     */
    public function update(
        UpdateProductRequest $request,
        Producto $product
    ): ProductResource {
        $validated = $request->validated();

        $data = [];
        if (isset($validated['nombre']) || isset($validated['name'])) {
            $data['nombre'] = $validated['nombre'] ?? $validated['name'];
        }
        if (isset($validated['sku']) || isset($validated['barcode'])) {
            $data['sku'] = $validated['sku'] ?? $validated['barcode'];
        }
        if (isset($validated['stock_actual']) || isset($validated['quantity'])) {
            $data['stock_actual'] = $validated['stock_actual'] ?? $validated['quantity'];
        }
        if (isset($validated['stock_minimo'])) {
            $data['stock_minimo'] = $validated['stock_minimo'];
        }
        if (array_key_exists('categoria_id', $validated)) {
            $data['categoria_id'] = $validated['categoria_id'];
        }
        if (array_key_exists('carrera_id', $validated)) {
            $data['carrera_id'] = $validated['carrera_id'];
        }
        if (isset($validated['tipo'])) {
            $data['tipo'] = $validated['tipo'];
        }
        if (array_key_exists('precio_referencia', $validated)) {
            $data['precio_referencia'] = $validated['precio_referencia'];
        }
        if (isset($validated['foto_url']) || isset($validated['photo_url'])) {
            $data['foto_url'] = $validated['foto_url'] ?? $validated['photo_url'];
        }

        $product->update($data);

        return new ProductResource($product->load(['categoria', 'carrera', 'equipos.estante.sala']));
    }

    /**
     * Remove the specified product from storage.
     */
    public function destroy(Producto $product): JsonResponse
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
    public function updateStatus(Request $request, Producto $product): JsonResponse
    {
        Gate::authorize('updateStatus', $product);

        return response()->json([
            'message' => 'Estado de producto actualizado exitosamente.',
            'product' => new ProductResource($product->load(['categoria', 'carrera', 'equipos'])),
        ]);
    }

    /**
     * Get location details for the product (REQ-05).
     */
    public function location(Producto $product): JsonResponse
    {
        Gate::authorize('view', $product);

        $product->loadMissing(['equipos.estante.sala']);
        $equipos = $product->equipos;

        if ($equipos->isEmpty()) {
            return response()->json([
                'message' => 'El producto no tiene unidades o ubicaciones físicas asignadas.',
                'product_id' => $product->id,
                'product_name' => $product->nombre,
                'ubicaciones' => [],
            ], 200);
        }

        $ubicaciones = $equipos->map(fn ($e) => [
            'equipo_id' => $e->id,
            'codigo_barras' => $e->codigo_barras,
            'numero_serie' => $e->numero_serie,
            'estado' => $e->estado,
            'estante_id' => $e->estante_id,
            'estante' => $e->estante?->codigo,
            'sala_id' => $e->estante?->sala_id,
            'sala' => $e->estante?->sala?->nombre,
        ]);

        return response()->json([
            'product_id' => $product->id,
            'product_name' => $product->nombre,
            'ubicaciones' => $ubicaciones,
        ]);
    }

    /**
     * Get barcode representation (Code128 SVG and URI) for the product (REQ-04).
     */
    public function barcode(Producto $product, BarcodeService $barcodeService): JsonResponse
    {
        Gate::authorize('view', $product);

        $barcode = $product->sku ?? $product->equipos->first()?->codigo_barras ?? 'SGIA-000000';

        return response()->json([
            'product_id' => $product->id,
            'product_name' => $product->nombre,
            'barcode' => $barcode,
            'barcode_svg' => $barcodeService->generateSvg($barcode),
            'barcode_image_uri' => $barcodeService->generateBarcodeImageUri($barcode),
            'barcode_html' => $barcodeService->generateHtml($barcode),
        ]);
    }
}
