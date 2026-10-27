<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Requests\UpdateUserStatusRequest;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Usuario::class);

        $query = Usuario::with(['rol', 'panol', 'carreras']);

        if ($request->filled('role')) {
            $role = $request->string('role');
            $rolId = match ($role->toString()) {
                'AD-01', 'Administrador' => 1,
                'DIR-01', 'Director de carrera' => 2,
                'PAN-01', 'Pañol' => 3,
                'Ayudante de pañol' => 4,
                'PRO-01', 'Docente' => 5,
                default => null,
            };
            if ($rolId) {
                $query->where('rol_id', $rolId);
            }
        }

        if ($request->filled('rol_id')) {
            $query->where('rol_id', $request->integer('rol_id'));
        }

        if ($request->filled('panol_id')) {
            $query->where('panol_id', $request->integer('panol_id'));
        }

        if ($request->has('is_active') || $request->has('activo')) {
            $isActive = $request->has('is_active') ? $request->boolean('is_active') : $request->boolean('activo');
            $query->where('activo', $isActive);
        }

        if ($request->filled('search')) {
            $search = '%' . $request->string('search') . '%';
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'ilike', $search)
                  ->orWhere('email', 'ilike', $search);
            });
        }

        $perPage = max(1, min(100, $request->integer('per_page', 15)));
        $users = $query->orderByDesc('created_at')->paginate($perPage);

        return UserResource::collection($users);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $nombre = $validated['nombre'] ?? $validated['name'];
        $rolId = $validated['rol_id'] ?? null;
        if (! $rolId && ! empty($validated['role'])) {
            $rolId = match ($validated['role']) {
                'AD-01', 'Administrador' => 1,
                'DIR-01', 'Director de carrera' => 2,
                'PAN-01', 'Pañol' => 3,
                'Ayudante de pañol' => 4,
                'PRO-01', 'Docente' => 5,
                default => 5,
            };
        }
        if (! $rolId) {
            $rolId = 5; // Default Docente
        }

        $isActive = true;
        if (isset($validated['activo'])) {
            $isActive = (bool) $validated['activo'];
        } elseif (isset($validated['is_active'])) {
            $isActive = (bool) $validated['is_active'];
        }

        $user = Usuario::create([
            'nombre' => $nombre,
            'email' => $validated['email'],
            'rol_id' => $rolId,
            'panol_id' => $validated['panol_id'] ?? null,
            'password' => Hash::make($validated['password']),
            'activo' => $isActive,
        ]);

        if (! empty($validated['carreras'])) {
            $user->carreras()->sync($validated['carreras']);
        }

        return (new UserResource($user->load(['rol', 'panol', 'carreras'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Usuario $user): UserResource
    {
        Gate::authorize('view', $user);

        return new UserResource($user->load(['rol', 'panol', 'carreras']));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateUserRequest $request, Usuario $user): UserResource
    {
        $validated = $request->validated();

        $data = [];
        if (isset($validated['nombre']) || isset($validated['name'])) {
            $data['nombre'] = $validated['nombre'] ?? $validated['name'];
        }
        if (isset($validated['email'])) {
            $data['email'] = $validated['email'];
        }
        if (isset($validated['rol_id'])) {
            $data['rol_id'] = $validated['rol_id'];
        } elseif (! empty($validated['role'])) {
            $data['rol_id'] = match ($validated['role']) {
                'AD-01', 'Administrador' => 1,
                'DIR-01', 'Director de carrera' => 2,
                'PAN-01', 'Pañol' => 3,
                'Ayudante de pañol' => 4,
                'PRO-01', 'Docente' => 5,
                default => $user->rol_id,
            };
        }
        if (array_key_exists('panol_id', $validated)) {
            $data['panol_id'] = $validated['panol_id'];
        }
        if (isset($validated['activo'])) {
            $data['activo'] = (bool) $validated['activo'];
        } elseif (isset($validated['is_active'])) {
            $data['activo'] = (bool) $validated['is_active'];
        }

        if (! empty($validated['password'])) {
            $data['password'] = Hash::make($validated['password']);
        }

        $user->update($data);

        if (array_key_exists('carreras', $validated)) {
            $user->carreras()->sync($validated['carreras'] ?? []);
        }

        return new UserResource($user->load(['rol', 'panol', 'carreras']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Usuario $user): JsonResponse
    {
        Gate::authorize('delete', $user);

        $user->tokens()->delete();
        $user->carreras()->detach();
        $user->delete();

        return response()->json([
            'message' => 'Usuario eliminado exitosamente.',
        ]);
    }

    /**
     * Update the active status of the specified user.
     */
    public function updateStatus(UpdateUserStatusRequest $request, Usuario $user): JsonResponse
    {
        Gate::authorize('updateStatus', $user);

        $isActive = $request->has('activo') ? $request->boolean('activo') : $request->boolean('is_active');

        $user->forceFill(['activo' => $isActive])->save();

        if (! $isActive) {
            // Revoke active sessions for deactivated user
            $user->tokens()->delete();
        }

        return response()->json([
            'message' => 'Estado de usuario actualizado exitosamente.',
            'user' => new UserResource($user->load(['rol', 'panol', 'carreras'])),
        ]);
    }
}
