<?php

namespace App\Http\Controllers\Api;

use App\Enums\SupplierArea;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSupplierRequest;
use App\Http\Requests\UpdateSupplierRequest;
use App\Http\Requests\UpdateSupplierStatusRequest;
use App\Http\Resources\SupplierResource;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SupplierController extends Controller
{
    /**
     * Display a listing of suppliers with filters.
     *
     * Query params: category, area, is_active, search, per_page.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Supplier::class);

        $request->validate(
            ['area' => ['sometimes', 'string', Rule::enum(SupplierArea::class)]],
            ['area.enum' => 'El área debe ser: '.implode(', ', SupplierArea::values()).'.'],
        );

        $query = Supplier::withCount('products');

        if ($request->filled('category')) {
            $query->where('category', 'ilike', '%'.$request->string('category').'%');
        }

        if ($request->filled('area')) {
            $query->inAreas([$request->string('area')->toString()]);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('search')) {
            $search = '%'.$request->string('search').'%';
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', $search)
                    ->orWhere('contact_name', 'ilike', $search)
                    ->orWhere('email', 'ilike', $search);
            });
        }

        $perPage = $this->perPage($request);
        $suppliers = $query->orderBy('name')->paginate($perPage);

        return SupplierResource::collection($suppliers);
    }

    /**
     * Store a newly created supplier.
     */
    public function store(StoreSupplierRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $supplier = DB::transaction(function () use ($validated) {
            $supplier = Supplier::create([
                'name' => $validated['name'],
                'contact_name' => $validated['contact_name'] ?? null,
                'email' => $validated['email'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'rut' => $validated['rut'] ?? null,
                'address' => $validated['address'] ?? null,
                'category' => $validated['category'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
            ]);
            $supplier->syncAreas($validated['areas']);

            return $supplier;
        });

        return (new SupplierResource($supplier))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the supplier with the products it has supplied.
     */
    public function show(Supplier $supplier): SupplierResource
    {
        Gate::authorize('view', $supplier);

        return new SupplierResource($supplier->load('products'));
    }

    /**
     * Update the specified supplier.
     */
    public function update(UpdateSupplierRequest $request, Supplier $supplier): SupplierResource
    {
        $validated = $request->validated();

        DB::transaction(function () use ($supplier, $validated) {
            $supplier->update(collect($validated)->except('areas')->all());

            if (array_key_exists('areas', $validated)) {
                $supplier->syncAreas($validated['areas']);
            }
        });

        return new SupplierResource($supplier);
    }

    /**
     * Activate or suspend the supplier.
     */
    public function updateStatus(UpdateSupplierStatusRequest $request, Supplier $supplier): JsonResponse
    {
        $supplier->forceFill(['is_active' => $request->boolean('is_active')])->save();

        return response()->json([
            'message' => 'Estado del proveedor actualizado exitosamente.',
            'supplier' => new SupplierResource($supplier),
        ]);
    }

    /**
     * Remove the supplier (AD-01). If it has linked products it is only
     * deactivated, to preserve the inventory history.
     */
    public function destroy(Supplier $supplier): Response|JsonResponse
    {
        Gate::authorize('delete', $supplier);

        if ($supplier->quotationSuppliers()->exists()) {
            return $this->unprocessable('El proveedor tiene cotizaciones registradas; desactívalo en lugar de eliminarlo.', 'supplier');
        }

        if ($supplier->products()->exists()) {
            $supplier->forceFill(['is_active' => false])->save();

            return response()->json([
                'message' => 'El proveedor tiene productos asociados: fue desactivado en lugar de eliminado.',
                'supplier' => new SupplierResource($supplier),
            ]);
        }

        $supplier->delete();

        return response()->noContent();
    }
}
