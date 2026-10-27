<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSupplierRequest;
use App\Http\Requests\UpdateSupplierRequest;
use App\Http\Requests\UpdateSupplierStatusRequest;
use App\Http\Resources\SupplierResource;
use App\Models\Proveedor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class SupplierController extends Controller
{
    /**
     * Display a listing of suppliers with filters.
     *
     * Query params: category, is_active, search, per_page.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Proveedor::class);

        $query = Proveedor::query();

        if ($request->has('is_active') || $request->has('activo')) {
            $isActive = $request->boolean('activo', $request->boolean('is_active'));
            $query->where('activo', $isActive);
        }

        if ($request->filled('search')) {
            $search = '%' . $request->string('search') . '%';
            $query->where(function ($q) use ($search) {
                $q->where('razon_social', 'ilike', $search)
                    ->orWhere('nombre_contacto', 'ilike', $search)
                    ->orWhere('email', 'ilike', $search)
                    ->orWhere('rut', 'ilike', $search);
            });
        }

        $perPage = max(1, min(100, $request->integer('per_page', 15)));
        $suppliers = $query->orderBy('razon_social')->paginate($perPage);

        return SupplierResource::collection($suppliers);
    }

    /**
     * Store a newly created supplier.
     */
    public function store(StoreSupplierRequest $request): JsonResponse
    {
        Gate::authorize('create', Proveedor::class);

        $validated = $request->validated();
        $rut = $validated['rut'] ?? sprintf('%08d-%s', mt_rand(10000000, 99999999), mt_rand(0, 9));

        $supplier = Proveedor::create([
            'rut' => $rut,
            'razon_social' => $validated['razon_social'] ?? $validated['name'],
            'nombre_contacto' => $validated['nombre_contacto'] ?? $validated['contact_name'] ?? null,
            'email' => $validated['email'] ?? null,
            'telefono' => $validated['telefono'] ?? $validated['phone'] ?? null,
            'direccion' => $validated['direccion'] ?? null,
            'activo' => (bool) ($validated['activo'] ?? $validated['is_active'] ?? true),
        ]);

        return (new SupplierResource($supplier))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the supplier.
     */
    public function show(Proveedor $supplier): SupplierResource
    {
        Gate::authorize('view', $supplier);

        return new SupplierResource($supplier);
    }

    /**
     * Update the specified supplier.
     */
    public function update(UpdateSupplierRequest $request, Proveedor $supplier): SupplierResource
    {
        $validated = $request->validated();
        $payload = [];

        if (isset($validated['rut'])) {
            $payload['rut'] = $validated['rut'];
        }
        if (isset($validated['razon_social'])) {
            $payload['razon_social'] = $validated['razon_social'];
        } elseif (isset($validated['name'])) {
            $payload['razon_social'] = $validated['name'];
        }
        if (array_key_exists('nombre_contacto', $validated)) {
            $payload['nombre_contacto'] = $validated['nombre_contacto'];
        } elseif (array_key_exists('contact_name', $validated)) {
            $payload['nombre_contacto'] = $validated['contact_name'];
        }
        if (array_key_exists('email', $validated)) {
            $payload['email'] = $validated['email'];
        }
        if (array_key_exists('telefono', $validated)) {
            $payload['telefono'] = $validated['telefono'];
        } elseif (array_key_exists('phone', $validated)) {
            $payload['telefono'] = $validated['phone'];
        }
        if (array_key_exists('direccion', $validated)) {
            $payload['direccion'] = $validated['direccion'];
        }
        if (isset($validated['activo'])) {
            $payload['activo'] = (bool) $validated['activo'];
        } elseif (isset($validated['is_active'])) {
            $payload['activo'] = (bool) $validated['is_active'];
        }

        $supplier->update($payload);

        return new SupplierResource($supplier);
    }

    /**
     * Activate or suspend the supplier.
     */
    public function updateStatus(UpdateSupplierStatusRequest $request, Proveedor $supplier): JsonResponse
    {
        $isActive = $request->boolean('activo', $request->boolean('is_active'));
        $supplier->forceFill(['activo' => $isActive])->save();

        return response()->json([
            'message' => 'Estado del proveedor actualizado exitosamente.',
            'supplier' => new SupplierResource($supplier),
        ]);
    }

    /**
     * Remove the supplier (AD-01). If it has linked records it is only
     * deactivated, to preserve historical integrity.
     */
    public function destroy(Proveedor $supplier): JsonResponse
    {
        Gate::authorize('delete', $supplier);

        if ($supplier->cotizaciones()->exists() || $supplier->registrosMantencion()->exists()) {
            $supplier->forceFill(['activo' => false])->save();

            return response()->json([
                'message' => 'El proveedor tiene cotizaciones o mantenciones asociadas: fue desactivado en lugar de eliminado.',
                'supplier' => new SupplierResource($supplier),
            ]);
        }

        $supplier->delete();

        return response()->json([
            'message' => 'Proveedor eliminado exitosamente.',
        ]);
    }
}
