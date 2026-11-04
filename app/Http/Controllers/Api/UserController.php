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
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
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

        $query = User::with('careers');

        if ($request->filled('storeroom_id')) {
            $query->where('storeroom_id', $request->integer('storeroom_id'));
        }

        if ($request->filled('career_id')) {
            $query->whereHas('careers', fn ($q) => $q->where('careers.id', $request->integer('career_id')));
        }

        if ($request->filled('role')) {
            $query->withRole($request->string('role')->toString());
        }

        if ($request->filled('area')) {
            $query->where('area', $request->string('area'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('search')) {
            $search = '%'.$request->string('search').'%';
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', $search)
                    ->orWhere('email', 'ilike', $search);
            });
        }

        $perPage = $this->perPage($request);
        $users = $query->orderByDesc('created_at')->paginate($perPage);

        return UserResource::collection($users);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = DB::transaction(function () use ($validated) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'role' => $validated['role'],
                'area' => $validated['area'] ?? null,
                'password' => Hash::make($validated['password']),
                'is_active' => $validated['is_active'] ?? true,
                'storeroom_id' => $validated['storeroom_id'] ?? null,
            ]);
            $user->careers()->sync($validated['career_ids'] ?? []);

            return $user;
        });

        return (new UserResource($user->load('careers')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, User $user): UserResource
    {
        Gate::authorize('view', $user);

        return new UserResource($user->load('careers'));
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

        DB::transaction(function () use ($user, $validated) {
            $user->update(collect($validated)->except('career_ids')->all());

            if (array_key_exists('career_ids', $validated)) {
                $user->careers()->sync($validated['career_ids']);
            }
        });

        return new UserResource($user->load('careers'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, User $user): Response|JsonResponse
    {
        Gate::authorize('delete', $user);

        $user->tokens()->delete();
        $user->loginRecords()->delete();
        $user->delete();

        return response()->noContent();
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
