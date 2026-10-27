<?php

namespace App\Policies;

use App\Models\Usuario;

class SupplierPolicy
{
    /**
     * Determine whether the user can view any suppliers (AD-01, DIR-01, PAN-01).
     */
    public function viewAny(Usuario $user): bool
    {
        return $user->activo && $user->hasRole([
            Usuario::ROLE_ADMIN,
            Usuario::ROLE_DIRECTOR,
            Usuario::ROLE_WAREHOUSE,
        ]);
    }

    /**
     * Determine whether the user can view the supplier.
     */
    public function view(Usuario $user, mixed $supplier): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can create suppliers (AD-01, DIR-01).
     */
    public function create(Usuario $user): bool
    {
        return $user->activo && $user->hasRole([
            Usuario::ROLE_ADMIN,
            Usuario::ROLE_DIRECTOR,
        ]);
    }

    /**
     * Determine whether the user can update the supplier (AD-01, DIR-01).
     */
    public function update(Usuario $user, mixed $supplier): bool
    {
        return $this->create($user);
    }

    /**
     * Determine whether the user can update the supplier status (AD-01, DIR-01).
     */
    public function updateStatus(Usuario $user, mixed $supplier): bool
    {
        return $this->create($user);
    }

    /**
     * Determine whether the user can delete a supplier (AD-01).
     */
    public function delete(Usuario $user, mixed $supplier): bool
    {
        return $user->activo && $user->isAdmin();
    }
}
