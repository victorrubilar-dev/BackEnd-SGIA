<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Requests\UpdateUserStatusRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
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
        Gate::authorize('viewAny', User::class);

        $query = User::query();

        if ($request->filled('role')) {
            $query->where('role', $request->string('role'));
        }

        if ($request->filled('area')) {
            $query->where('area', $request->string('area'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('search')) {
            $search = '%' . $request->string('search') . '%';
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', $search)
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

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => $validated['role'],
            'area' => $validated['area'] ?? null,
            'password' => Hash::make($validated['password']),
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return (new UserResource($user))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, User $user): UserResource
    {
        Gate::authorize('view', $user);

        return new UserResource($user);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $validated = $request->validated();

        if (! empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        $user->update($validated);

        return new UserResource($user);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        Gate::authorize('delete', $user);

        $user->tokens()->delete();
        $user->loginRecords()->delete();
        $user->delete();

        return response()->json([
            'message' => 'Usuario eliminado exitosamente.',
        ]);
    }

    /**
     * Update the active status of the specified user.
     */
    public function updateStatus(UpdateUserStatusRequest $request, User $user): JsonResponse
    {
        $isActive = $request->boolean('is_active');

        $user->forceFill(['is_active' => $isActive])->save();

        if (! $isActive) {
            // Revoke active sessions for deactivated user
            $user->tokens()->delete();
        }

        return response()->json([
            'message' => 'Estado de usuario actualizado exitosamente.',
            'user' => new UserResource($user),
        ]);
    }
}
