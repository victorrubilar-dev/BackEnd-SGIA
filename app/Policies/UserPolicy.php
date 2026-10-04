<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, User $model): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, User $model): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, User $model): Response
    {
        if (! $user->isAdmin()) {
            return Response::deny('No tienes permisos de administrador.');
        }

        if ($user->id === $model->id) {
            return Response::deny('No puedes eliminar tu propia cuenta de administrador.');
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can update the status of the model.
     */
    public function updateStatus(User $user, User $model): Response
    {
        if (! $user->isAdmin()) {
            return Response::deny('No tienes permisos de administrador.');
        }

        if ($user->id === $model->id) {
            return Response::deny('No puedes desactivar tu propia cuenta de administrador.');
        }

        return Response::allow();
    }
}
