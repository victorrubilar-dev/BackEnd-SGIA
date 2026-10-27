<?php

namespace App\Policies;

use App\Models\Usuario;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(Usuario $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(Usuario $user, mixed $model): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(Usuario $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(Usuario $user, mixed $model): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(Usuario $user, mixed $model): Response
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
    public function updateStatus(Usuario $user, mixed $model): Response
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
